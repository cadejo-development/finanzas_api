<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\PlanillaSP;
use App\Models\RRHH\PlanillaSPLinea;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PlanillaSPController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    // Retención ISR fija para servicios profesionales (Art. 156 Ley ISR El Salvador)
    private const RETENCION_ISR_PCT = 0.10;

    // ─── Listado ─────────────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas-sp
     */
    public function index(Request $request): JsonResponse
    {
        $query = PlanillaSP::query();

        if ($anio = $request->input('anio'))   $query->where('anio', (int) $anio);
        if ($mes  = $request->input('mes'))    $query->where('mes',  (int) $mes);

        $planillas = $query->with('lineas')->orderByDesc('anio')->orderByDesc('mes')->orderByDesc('id')->get();

        $data = $planillas->map(function ($p) {
            $arr = $p->toArray();
            unset($arr['lineas']);
            $arr['lineas_count']      = $p->lineas->count();
            $arr['total_honorarios']  = round((float) $p->lineas->sum('monto_honorario'), 2);
            $arr['total_neto']        = round((float) $p->lineas->sum('liquido'), 2);
            return $arr;
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ─── Detalle ─────────────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas-sp/{id}
     */
    public function show(int $id): JsonResponse
    {
        $planilla = PlanillaSP::with('lineas')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $planilla]);
    }

    // ─── Crear planilla ───────────────────────────────────────────────────────

    /**
     * POST /api/rrhh/planillas-sp
     */
    public function store(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $validated = $request->validate([
                'anio'        => 'required|integer|min:2020|max:2099',
                'mes'         => 'required|integer|min:1|max:12',
                'quincena'    => 'nullable|integer|in:1,2',
                'descripcion' => 'nullable|string|max:200',
                'sucursal_id' => 'nullable|integer',
            ]);

            $planilla = PlanillaSP::create([
                'anio'        => (int) $validated['anio'],
                'mes'         => (int) $validated['mes'],
                'quincena'    => isset($validated['quincena']) ? (int) $validated['quincena'] : null,
                'descripcion' => $validated['descripcion'] ?? null,
                'sucursal_id' => isset($validated['sucursal_id']) ? (int) $validated['sucursal_id'] : null,
                'estado'      => 'borrador',
                'creado_por'  => Auth::id(),
            ]);

            return response()->json(['success' => true, 'data' => $planilla], 201);
        });
    }

    // ─── Cargar nuevo ingreso ─────────────────────────────────────────────────

    /**
     * POST /api/rrhh/planillas-sp/{id}/cargar-nuevo-ingreso
     *
     * Busca empleados con confirmacion='se_presento' en ingresos_personal cuya
     * fecha_ingreso cae dentro del período de 30 días de SP que toca esta quincena,
     * Recalcula las líneas de nuevo ingreso: elimina las existentes cargadas automáticamente
     * y las recrea con monto proporcional: salario_base/30 × días trabajados en la quincena.
     * Empleados ya presentes en la planilla se omiten.
     */
    public function cargarNuevoIngreso(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $planilla = PlanillaSP::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'Solo se puede cargar en planillas en borrador.'], 422);
            }

            if (!$planilla->quincena) {
                return response()->json(['success' => false, 'message' => 'La planilla no tiene quincena definida. Elimínala y crea una nueva con quincena.'], 422);
            }

            [$fechaInicioQ, $fechaFinQ] = $this->rangoQuincena(
                (int) $planilla->anio,
                (int) $planilla->mes,
                (int) $planilla->quincena
            );

            // Un empleado en SP de 30 días cae en esta quincena si:
            //   fecha_ingreso ≤ fin quincena  (ya empezó)
            //   fecha_ingreso ≥ inicio quincena - 30 días  (el período aún no terminó)
            $cutoff = Carbon::parse($fechaInicioQ)->subDays(30)->toDateString();

            $query = DB::connection('rrhh')
                ->table('ingresos_personal as ip')
                ->where('ip.confirmacion', 'se_presento')
                ->where('ip.fecha_ingreso', '>=', $cutoff)
                ->where('ip.fecha_ingreso', '<=', $fechaFinQ)
                ->select('ip.empleado_id', 'ip.fecha_ingreso',
                         'ip.empleado_nombre', 'ip.cargo_nombre', 'ip.sucursal_nombre')
                ->groupBy('ip.empleado_id', 'ip.fecha_ingreso',
                          'ip.empleado_nombre', 'ip.cargo_nombre', 'ip.sucursal_nombre');

            // Filtrar por sucursal si la planilla tiene una
            if ($planilla->sucursal_id) {
                $empIds = DB::connection('pgsql')
                    ->table('empleados')
                    ->where('sucursal_id', $planilla->sucursal_id)
                    ->pluck('id');
                $query->whereIn('ip.empleado_id', $empIds);
            }

            $ingresos = $query->get();

            if ($ingresos->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No se encontraron empleados de nuevo ingreso para este período.',
                    'agregados' => 0,
                ]);
            }

            // Obtener salario_base de cada empleado
            $empIds = $ingresos->pluck('empleado_id')->unique()->values()->all();

            // fecha_fin_estimada de periodos_prueba para capear los días SP correctamente
            // (el empleado solo cobra SP hasta que termina su periodo, no hasta fin quincena)
            $fechaFinPruebaMap = DB::connection('rrhh')
                ->table('periodos_prueba')
                ->whereIn('empleado_id', $empIds)
                ->pluck('fecha_fin_estimada', 'empleado_id');
            $empleados = DB::connection('pgsql')
                ->table('empleados')
                ->whereIn('id', $empIds)
                ->select('id', 'salario_base', 'nombres', 'apellidos')
                ->get()
                ->keyBy('id');

            $inicioQ = Carbon::parse($fechaInicioQ);
            $finQ    = Carbon::parse($fechaFinQ);
            $diasQ   = $inicioQ->diffInDays($finQ) + 1; // 15 para Q1, variable para Q2

            // Cargar permisos aprobados de día completo en el período (batch)
            $permisosRaw = DB::connection('rrhh')
                ->table('permisos')
                ->whereIn('empleado_id', $empIds)
                ->where('estado', 'aprobado')
                ->where('es_dia_completo', true)
                ->where('fecha', '<=', $fechaFinQ)
                ->where(function ($q) use ($fechaInicioQ) {
                    // Permiso que termina después del inicio: fecha + dias - 1 >= inicioQ
                    // Simplificamos filtrando fecha >= inicioQ - 30 días y calculamos solapamiento en PHP
                    $q->where('fecha', '>=', Carbon::parse($fechaInicioQ)->subDays(30)->toDateString());
                })
                ->select('empleado_id', 'fecha', DB::raw('COALESCE(dias, 1) as dias_permiso'))
                ->get();

            // Cargar incapacidades que cruzan el período (batch)
            $incapacidadesRaw = DB::connection('rrhh')
                ->table('incapacidades')
                ->whereIn('empleado_id', $empIds)
                ->where('fecha_inicio', '<=', $fechaFinQ)
                ->where('fecha_fin',    '>=', $fechaInicioQ)
                ->select('empleado_id', 'fecha_inicio', 'fecha_fin')
                ->get();

            // Precalcular días de permiso e incapacidad por empleado en el período
            $diasPermisoMap      = [];
            $diasIncapacidadMap  = [];

            foreach ($permisosRaw as $p) {
                $permisoInicio = Carbon::parse($p->fecha);
                $permisoFin    = $permisoInicio->copy()->addDays((int) $p->dias_permiso - 1);
                // Solapamiento con [inicioQ, finQ]
                $overlapStart  = $permisoInicio->lt($inicioQ) ? $inicioQ : $permisoInicio;
                $overlapEnd    = $permisoFin->gt($finQ)       ? $finQ    : $permisoFin;
                $dias          = max(0, $overlapStart->diffInDays($overlapEnd) + 1);
                $diasPermisoMap[$p->empleado_id] = ($diasPermisoMap[$p->empleado_id] ?? 0) + $dias;
            }

            foreach ($incapacidadesRaw as $inc) {
                $incInicio    = Carbon::parse($inc->fecha_inicio);
                $incFin       = Carbon::parse($inc->fecha_fin);
                $overlapStart = $incInicio->lt($inicioQ) ? $inicioQ : $incInicio;
                $overlapEnd   = $incFin->gt($finQ)       ? $finQ    : $incFin;
                $dias         = max(0, $overlapStart->diffInDays($overlapEnd) + 1);
                $diasIncapacidadMap[$inc->empleado_id] = ($diasIncapacidadMap[$inc->empleado_id] ?? 0) + $dias;
            }

            // IDs de empleados de nuevo ingreso en el período (para borrar solo esas líneas al recalcular)
            $empIdsIngreso = DB::connection('rrhh')
                ->table('ingresos_personal')
                ->where('confirmacion', 'se_presento')
                ->where('fecha_ingreso', '>=', $cutoff)
                ->where('fecha_ingreso', '<=', $fechaFinQ)
                ->pluck('empleado_id')
                ->toArray();

            DB::connection('rrhh')->beginTransaction();
            try {
                // Solo eliminar líneas de nuevo ingreso previas para recalcular
                // sin afectar las líneas de honorarios regulares cargadas via cargarHonorarios
                if (!empty($empIdsIngreso)) {
                    PlanillaSPLinea::where('planilla_sp_id', $id)
                        ->whereIn('empleado_id', $empIdsIngreso)
                        ->delete();
                }

                $agregados = 0;
                foreach ($ingresos as $ing) {
                    $emp = $empleados[$ing->empleado_id] ?? null;
                    $salarioBase = $emp ? (float) $emp->salario_base : 0;

                    // Días del período: desde la fecha de ingreso (o inicio Q si ingresó antes)
                    // hasta el fin del periodo de prueba (o fin Q si termina después)
                    $fechaIng        = Carbon::parse($ing->fecha_ingreso);
                    $inicioEfectiva  = $fechaIng->gt($inicioQ) ? $fechaIng->copy() : $inicioQ->copy();
                    $fechaFinPrueba  = isset($fechaFinPruebaMap[$ing->empleado_id])
                        ? Carbon::parse($fechaFinPruebaMap[$ing->empleado_id])
                        : $finQ;
                    $finEfectiva     = $fechaFinPrueba->lt($finQ) ? $fechaFinPrueba : $finQ;
                    $diasPeriodo     = max(0, $inicioEfectiva->diffInDays($finEfectiva) + 1);

                    // Descontar permisos e incapacidades
                    $diasPermiso      = min($diasPermisoMap[$ing->empleado_id] ?? 0, $diasPeriodo);
                    $diasIncapacidad  = min($diasIncapacidadMap[$ing->empleado_id] ?? 0, $diasPeriodo - $diasPermiso);
                    $diasTrabajados   = max(0, $diasPeriodo - $diasPermiso - $diasIncapacidad);

                    $monto     = round($salarioBase / 30 * $diasTrabajados, 2);
                    $retencion = round($monto * self::RETENCION_ISR_PCT, 2);
                    $liquido   = round($monto - $retencion, 2);

                    $nombre = $emp
                        ? trim($emp->nombres . ' ' . $emp->apellidos)
                        : $ing->empleado_nombre;

                    $desc = 'Honorarios SP - ' . $ing->cargo_nombre . ' (' . $ing->fecha_ingreso . ')';
                    if ($diasPeriodo < $diasQ) {
                        $desc .= " — {$diasPeriodo} días en período";
                    }
                    if ($diasPermiso > 0 || $diasIncapacidad > 0) {
                        $desc .= " ({$diasTrabajados} trabajados)";
                    }

                    PlanillaSPLinea::create([
                        'planilla_sp_id'       => $id,
                        'empleado_id'          => $ing->empleado_id,
                        'nombre'               => $nombre,
                        'descripcion_servicio' => $desc,
                        'dias_periodo'         => $diasPeriodo,
                        'dias_permiso'         => $diasPermiso,
                        'dias_incapacidad'     => $diasIncapacidad,
                        'dias_trabajados'      => $diasTrabajados,
                        'monto_honorario'      => $monto,
                        'retencion_isr'        => $retencion,
                        'liquido'              => $liquido,
                    ]);
                    $agregados++;
                }

                $this->recalcularTotales($planilla);
                DB::connection('rrhh')->commit();
            } catch (\Throwable $e) {
                DB::connection('rrhh')->rollBack();
                throw $e;
            }

            return response()->json([
                'success'  => true,
                'message'  => "Se agregaron {$agregados} empleado(s) de nuevo ingreso.",
                'agregados' => $agregados,
                'data'     => $planilla->fresh()->load('lineas'),
            ]);
        });
    }

    // ─── Cerrar ───────────────────────────────────────────────────────────────

    /**
     * PUT /api/rrhh/planillas-sp/{id}/cerrar
     */
    public function cerrar(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $planilla = PlanillaSP::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'Solo se puede cerrar una planilla SP en borrador.'], 422);
            }

            $planilla->update(['estado' => 'cerrada']);

            return response()->json(['success' => true, 'message' => 'Planilla SP cerrada.', 'data' => $planilla->fresh()]);
        });
    }

    // ─── Eliminar planilla ────────────────────────────────────────────────────

    /**
     * DELETE /api/rrhh/planillas-sp/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $planilla = PlanillaSP::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'Solo se pueden eliminar planillas SP en borrador.'], 422);
            }

            $planilla->delete();
            return response()->json(['success' => true, 'message' => 'Planilla SP eliminada.']);
        });
    }

    // ─── Líneas CRUD ─────────────────────────────────────────────────────────

    /**
     * POST /api/rrhh/planillas-sp/{id}/lineas
     * Agrega un prestador de servicios a la planilla.
     */
    public function storeLinea(int $id, Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($id, $request) {
            $planilla = PlanillaSP::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'Solo se pueden agregar líneas a planillas SP en borrador.'], 422);
            }

            $validated = $request->validate([
                'nombre'              => 'required|string|max:150',
                'nit'                 => 'nullable|string|max:20',
                'descripcion_servicio'=> 'nullable|string|max:200',
                'monto_honorario'     => 'required|numeric|min:0.01',
                'notas'               => 'nullable|string',
            ]);

            $monto     = round((float) $validated['monto_honorario'], 2);
            $retencion = round($monto * self::RETENCION_ISR_PCT, 2);
            $liquido   = round($monto - $retencion, 2);

            DB::connection('rrhh')->beginTransaction();
            try {
                $linea = PlanillaSPLinea::create([
                    'planilla_sp_id'       => $id,
                    'nombre'               => $validated['nombre'],
                    'nit'                  => $validated['nit'] ?? null,
                    'descripcion_servicio' => $validated['descripcion_servicio'] ?? null,
                    'monto_honorario'      => $monto,
                    'retencion_isr'        => $retencion,
                    'liquido'              => $liquido,
                    'notas'                => $validated['notas'] ?? null,
                ]);

                $this->recalcularTotales($planilla);
                DB::connection('rrhh')->commit();
            } catch (\Throwable $e) {
                DB::connection('rrhh')->rollBack();
                throw $e;
            }

            return response()->json(['success' => true, 'data' => $linea], 201);
        });
    }

    /**
     * PUT /api/rrhh/planillas-sp/{id}/lineas/{lineaId}
     */
    public function updateLinea(int $id, int $lineaId, Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($id, $lineaId, $request) {
            $planilla = PlanillaSP::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'No se puede editar una planilla SP cerrada.'], 422);
            }

            $linea = PlanillaSPLinea::where('planilla_sp_id', $id)->findOrFail($lineaId);

            $validated = $request->validate([
                'nombre'              => 'sometimes|string|max:150',
                'nit'                 => 'nullable|string|max:20',
                'descripcion_servicio'=> 'nullable|string|max:200',
                'monto_honorario'     => 'sometimes|numeric|min:0.01',
                'notas'               => 'nullable|string',
            ]);

            if (isset($validated['monto_honorario'])) {
                $monto = round((float) $validated['monto_honorario'], 2);
                $validated['monto_honorario'] = $monto;
                $validated['retencion_isr']   = round($monto * self::RETENCION_ISR_PCT, 2);
                $validated['liquido']         = round($monto - $validated['retencion_isr'], 2);
            }

            DB::connection('rrhh')->beginTransaction();
            try {
                $linea->update($validated);
                $this->recalcularTotales($planilla);
                DB::connection('rrhh')->commit();
            } catch (\Throwable $e) {
                DB::connection('rrhh')->rollBack();
                throw $e;
            }

            return response()->json(['success' => true, 'data' => $linea->fresh()]);
        });
    }

    /**
     * DELETE /api/rrhh/planillas-sp/{id}/lineas/{lineaId}
     */
    public function destroyLinea(int $id, int $lineaId): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id, $lineaId) {
            $planilla = PlanillaSP::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'No se puede eliminar líneas de una planilla SP cerrada.'], 422);
            }

            $deleted = PlanillaSPLinea::where('planilla_sp_id', $id)->where('id', $lineaId)->delete();

            if (!$deleted) {
                return response()->json(['success' => false, 'message' => 'Línea no encontrada.'], 404);
            }

            $this->recalcularTotales($planilla);

            return response()->json(['success' => true, 'message' => 'Línea eliminada.']);
        });
    }

    // ─── Recalcular SP (honorarios + nuevo ingreso + totales) ────────────────

    /**
     * POST /api/rrhh/planillas-sp/{id}/recalcular
     *
     * Elimina todas las líneas autogeneradas (con empleado_id), luego recarga:
     *   1. Empleados con tipo_planilla = 'honorarios'
     *   2. Empleados de nuevo ingreso (ingresos_personal + periodos_prueba)
     * Las líneas manuales (sin empleado_id) se conservan.
     */
    public function recalcular(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $planilla = PlanillaSP::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'Solo se puede recalcular en planillas en borrador.'], 422);
            }

            if (!$planilla->quincena) {
                return response()->json(['success' => false, 'message' => 'La planilla no tiene quincena definida. Elimínala y crea una nueva con quincena.'], 422);
            }

            [$fechaInicioQ, $fechaFinQ] = $this->rangoQuincena(
                (int) $planilla->anio,
                (int) $planilla->mes,
                (int) $planilla->quincena
            );

            $inicioQ = Carbon::parse($fechaInicioQ);
            $finQ    = Carbon::parse($fechaFinQ);
            $diasQ   = $inicioQ->diffInDays($finQ) + 1;

            DB::connection('rrhh')->beginTransaction();
            try {
                // ── 1. Borrar todas las líneas autogeneradas ──────────────────
                PlanillaSPLinea::where('planilla_sp_id', $id)
                    ->whereNotNull('empleado_id')
                    ->delete();

                $agregadosHonorarios = 0;
                $agregadosIngreso    = 0;

                // ── 2. Cargar empleados tipo Honorarios ───────────────────────
                $honorariosIds = DB::table('empleado_tipo_planilla as etp')
                    ->join('tipo_planilla as tp', 'tp.id', '=', 'etp.tipo_planilla_id')
                    ->where('tp.slug', 'honorarios')
                    ->where('etp.fecha_inicio', '<=', $fechaFinQ)
                    ->where(function ($q) use ($fechaInicioQ) {
                        $q->whereNull('etp.fecha_fin')
                          ->orWhere('etp.fecha_fin', '>=', $fechaInicioQ);
                    })
                    ->pluck('etp.empleado_id');

                $empleadosHon = DB::connection('pgsql')
                    ->table('empleados as e')
                    ->whereIn('e.id', $honorariosIds)
                    ->where('e.activo', true)
                    ->when($planilla->sucursal_id, fn($q) => $q->where('e.sucursal_id', $planilla->sucursal_id))
                    ->select('e.id', 'e.nombres', 'e.apellidos', 'e.salario_base')
                    ->get();

                foreach ($empleadosHon as $emp) {
                    $nombre    = trim($emp->apellidos . ', ' . $emp->nombres);
                    $monto     = round((float) $emp->salario_base, 2);
                    $retencion = round($monto * self::RETENCION_ISR_PCT, 2);
                    $liquido   = round($monto - $retencion, 2);

                    PlanillaSPLinea::create([
                        'planilla_sp_id'       => $id,
                        'empleado_id'          => $emp->id,
                        'nombre'               => $nombre,
                        'descripcion_servicio' => 'Honorarios — tipo planilla asignado',
                        'monto_honorario'      => $monto,
                        'retencion_isr'        => $retencion,
                        'liquido'              => $liquido,
                    ]);
                    $agregadosHonorarios++;
                }

                // ── 3. Cargar empleados de nuevo ingreso ──────────────────────
                $cutoff = $inicioQ->copy()->subDays(30)->toDateString();

                // IDs de honorarios ya cargados (para no duplicar si alguien está en ambas fuentes)
                $yaPresentes = PlanillaSPLinea::where('planilla_sp_id', $id)
                    ->whereNotNull('empleado_id')
                    ->pluck('empleado_id')
                    ->all();

                $queryIng = DB::connection('rrhh')
                    ->table('ingresos_personal as ip')
                    ->where('ip.confirmacion', 'se_presento')
                    ->where('ip.fecha_ingreso', '>=', $cutoff)
                    ->where('ip.fecha_ingreso', '<=', $fechaFinQ)
                    ->whereNotIn('ip.empleado_id', $yaPresentes)
                    ->select('ip.empleado_id', 'ip.fecha_ingreso',
                             'ip.empleado_nombre', 'ip.cargo_nombre', 'ip.sucursal_nombre')
                    ->groupBy('ip.empleado_id', 'ip.fecha_ingreso',
                              'ip.empleado_nombre', 'ip.cargo_nombre', 'ip.sucursal_nombre');

                if ($planilla->sucursal_id) {
                    $empIdsSuc = DB::connection('pgsql')
                        ->table('empleados')
                        ->where('sucursal_id', $planilla->sucursal_id)
                        ->pluck('id');
                    $queryIng->whereIn('ip.empleado_id', $empIdsSuc);
                }

                $ingresos = $queryIng->get();

                if ($ingresos->isNotEmpty()) {
                    $empIdsIng = $ingresos->pluck('empleado_id')->unique()->values()->all();

                    $fechaFinPruebaMap = DB::connection('rrhh')
                        ->table('periodos_prueba')
                        ->whereIn('empleado_id', $empIdsIng)
                        ->pluck('fecha_fin_estimada', 'empleado_id');

                    $empleadosIng = DB::connection('pgsql')
                        ->table('empleados')
                        ->whereIn('id', $empIdsIng)
                        ->select('id', 'salario_base', 'nombres', 'apellidos')
                        ->get()
                        ->keyBy('id');

                    // Permisos e incapacidades batch
                    $permisosRaw = DB::connection('rrhh')
                        ->table('permisos')
                        ->whereIn('empleado_id', $empIdsIng)
                        ->where('estado', 'aprobado')
                        ->where('es_dia_completo', true)
                        ->where('fecha', '<=', $fechaFinQ)
                        ->where('fecha', '>=', $inicioQ->copy()->subDays(30)->toDateString())
                        ->select('empleado_id', 'fecha', DB::raw('COALESCE(dias, 1) as dias_permiso'))
                        ->get();

                    $incapacidadesRaw = DB::connection('rrhh')
                        ->table('incapacidades')
                        ->whereIn('empleado_id', $empIdsIng)
                        ->where('fecha_inicio', '<=', $fechaFinQ)
                        ->where('fecha_fin',    '>=', $fechaInicioQ)
                        ->select('empleado_id', 'fecha_inicio', 'fecha_fin')
                        ->get();

                    $diasPermisoMap     = [];
                    $diasIncapacidadMap = [];

                    foreach ($permisosRaw as $p) {
                        $pIni  = Carbon::parse($p->fecha);
                        $pFin  = $pIni->copy()->addDays((int) $p->dias_permiso - 1);
                        $oStart = $pIni->lt($inicioQ) ? $inicioQ : $pIni;
                        $oEnd   = $pFin->gt($finQ)    ? $finQ    : $pFin;
                        $dias   = max(0, $oStart->diffInDays($oEnd) + 1);
                        $diasPermisoMap[$p->empleado_id] = ($diasPermisoMap[$p->empleado_id] ?? 0) + $dias;
                    }

                    foreach ($incapacidadesRaw as $inc) {
                        $iIni  = Carbon::parse($inc->fecha_inicio);
                        $iFin  = Carbon::parse($inc->fecha_fin);
                        $oStart = $iIni->lt($inicioQ) ? $inicioQ : $iIni;
                        $oEnd   = $iFin->gt($finQ)    ? $finQ    : $iFin;
                        $dias   = max(0, $oStart->diffInDays($oEnd) + 1);
                        $diasIncapacidadMap[$inc->empleado_id] = ($diasIncapacidadMap[$inc->empleado_id] ?? 0) + $dias;
                    }

                    foreach ($ingresos as $ing) {
                        $emp         = $empleadosIng[$ing->empleado_id] ?? null;
                        $salarioBase = $emp ? (float) $emp->salario_base : 0;

                        $fechaIng       = Carbon::parse($ing->fecha_ingreso);
                        $inicioEfectiva = $fechaIng->gt($inicioQ) ? $fechaIng->copy() : $inicioQ->copy();
                        $fechaFinPrueba = isset($fechaFinPruebaMap[$ing->empleado_id])
                            ? Carbon::parse($fechaFinPruebaMap[$ing->empleado_id])
                            : $finQ;
                        $finEfectiva    = $fechaFinPrueba->lt($finQ) ? $fechaFinPrueba : $finQ;
                        $diasPeriodo    = max(0, $inicioEfectiva->diffInDays($finEfectiva) + 1);

                        $diasPermiso     = min($diasPermisoMap[$ing->empleado_id] ?? 0, $diasPeriodo);
                        $diasIncapacidad = min($diasIncapacidadMap[$ing->empleado_id] ?? 0, $diasPeriodo - $diasPermiso);
                        $diasTrabajados  = max(0, $diasPeriodo - $diasPermiso - $diasIncapacidad);

                        $monto     = round($salarioBase / 30 * $diasTrabajados, 2);
                        $retencion = round($monto * self::RETENCION_ISR_PCT, 2);
                        $liquido   = round($monto - $retencion, 2);

                        $nombre = $emp
                            ? trim($emp->nombres . ' ' . $emp->apellidos)
                            : $ing->empleado_nombre;

                        $desc = 'Honorarios SP - ' . $ing->cargo_nombre . ' (' . $ing->fecha_ingreso . ')';
                        if ($diasPeriodo < $diasQ) {
                            $desc .= " — {$diasPeriodo} días en período";
                        }
                        if ($diasPermiso > 0 || $diasIncapacidad > 0) {
                            $desc .= " ({$diasTrabajados} trabajados)";
                        }

                        PlanillaSPLinea::create([
                            'planilla_sp_id'       => $id,
                            'empleado_id'          => $ing->empleado_id,
                            'nombre'               => $nombre,
                            'descripcion_servicio' => $desc,
                            'dias_periodo'         => $diasPeriodo,
                            'dias_permiso'         => $diasPermiso,
                            'dias_incapacidad'     => $diasIncapacidad,
                            'dias_trabajados'      => $diasTrabajados,
                            'monto_honorario'      => $monto,
                            'retencion_isr'        => $retencion,
                            'liquido'              => $liquido,
                        ]);
                        $agregadosIngreso++;
                    }
                }

                // ── 4. Recalcular totales ─────────────────────────────────────
                $this->recalcularTotales($planilla);
                DB::connection('rrhh')->commit();
            } catch (\Throwable $e) {
                DB::connection('rrhh')->rollBack();
                throw $e;
            }

            return response()->json([
                'success'             => true,
                'message'             => "Recalculado: {$agregadosHonorarios} honorario(s), {$agregadosIngreso} nuevo(s) ingreso.",
                'agregados_honorarios' => $agregadosHonorarios,
                'agregados_ingreso'   => $agregadosIngreso,
                'data'                => $planilla->fresh()->load('lineas'),
            ]);
        });
    }

    // ─── Cargar honorarios marcados ───────────────────────────────────────────

    /**
     * POST /api/rrhh/planillas-sp/{id}/cargar-honorarios
     *
     * Busca empleados activos con tipo_planilla = 'honorarios' (asignados
     * explícitamente en empleado_tipo_planilla) que aún NO están en esta
     * planilla SP, y los agrega con monto_honorario = salario_base.
     */
    public function cargarHonorarios(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $planilla = PlanillaSP::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'Solo se pueden agregar líneas a planillas SP en borrador.'], 422);
            }

            // IDs ya presentes en la planilla (evitar duplicar)
            $yaPresentes = PlanillaSPLinea::where('planilla_sp_id', $id)
                ->whereNotNull('empleado_id')
                ->pluck('empleado_id')
                ->all();

            // Rango de la planilla SP para filtrar vigencia del tipo
            [$fechaInicioQ, $fechaFinQ] = $planilla->quincena
                ? $this->rangoQuincena($planilla->anio, $planilla->mes, $planilla->quincena)
                : [Carbon::create($planilla->anio, $planilla->mes, 1)->toDateString(),
                   Carbon::create($planilla->anio, $planilla->mes)->endOfMonth()->toDateString()];

            // Empleados activos marcados con tipo 'honorarios' vigentes en el período
            $honorariosIds = DB::table('empleado_tipo_planilla as etp')
                ->join('tipo_planilla as tp', 'tp.id', '=', 'etp.tipo_planilla_id')
                ->where('tp.slug', 'honorarios')
                ->where('etp.fecha_inicio', '<=', $fechaFinQ)
                ->where(function ($q) use ($fechaInicioQ) {
                    $q->whereNull('etp.fecha_fin')
                      ->orWhere('etp.fecha_fin', '>=', $fechaInicioQ);
                })
                ->pluck('etp.empleado_id');

            $empleados = DB::connection('pgsql')
                ->table('empleados as e')
                ->leftJoin('sucursales as s', 's.id', '=', 'e.sucursal_id')
                ->whereIn('e.id', $honorariosIds)
                ->where('e.activo', true)
                ->whereNotIn('e.id', $yaPresentes)
                ->select('e.id', 'e.nombres', 'e.apellidos', 'e.salario_base')
                ->get();

            if ($planilla->sucursal_id) {
                $empleados = $empleados->filter(function ($emp) use ($planilla) {
                    return DB::connection('pgsql')
                        ->table('empleados')
                        ->where('id', $emp->id)
                        ->where('sucursal_id', $planilla->sucursal_id)
                        ->exists();
                });
            }

            if ($empleados->isEmpty()) {
                return response()->json([
                    'success'  => true,
                    'message'  => 'No hay empleados marcados como Honorarios pendientes de agregar.',
                    'agregados' => 0,
                ]);
            }

            DB::connection('rrhh')->beginTransaction();
            try {
                $agregados = 0;
                foreach ($empleados as $emp) {
                    $nombre    = trim($emp->apellidos . ', ' . $emp->nombres);
                    $monto     = round((float) $emp->salario_base, 2);
                    $retencion = round($monto * self::RETENCION_ISR_PCT, 2);
                    $liquido   = round($monto - $retencion, 2);

                    PlanillaSPLinea::create([
                        'planilla_sp_id'       => $id,
                        'empleado_id'          => $emp->id,
                        'nombre'               => $nombre,
                        'descripcion_servicio' => 'Honorarios — tipo planilla asignado',
                        'monto_honorario'      => $monto,
                        'retencion_isr'        => $retencion,
                        'liquido'              => $liquido,
                    ]);
                    $agregados++;
                }

                $this->recalcularTotales($planilla);
                DB::connection('rrhh')->commit();
            } catch (\Throwable $e) {
                DB::connection('rrhh')->rollBack();
                throw $e;
            }

            return response()->json([
                'success'   => true,
                'message'   => "Se agregaron {$agregados} empleado(s) con tipo Honorarios.",
                'agregados' => $agregados,
                'data'      => $planilla->fresh()->load('lineas'),
            ]);
        });
    }

    // ─── Exportar ─────────────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas-sp/{id}/exportar
     */
    public function exportar(int $id): JsonResponse
    {
        $planilla = PlanillaSP::with('lineas')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => [
                'planilla' => $planilla,
                'lineas'   => $planilla->lineas,
                'totales'  => [
                    'total_honorarios' => (float) $planilla->total_honorarios,
                    'total_retencion'  => (float) $planilla->total_retencion,
                    'total_neto'       => (float) $planilla->total_neto,
                ],
            ],
        ]);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function recalcularTotales(PlanillaSP $planilla): void
    {
        $lineas = PlanillaSPLinea::where('planilla_sp_id', $planilla->id)->get();
        $planilla->update([
            'total_honorarios' => round($lineas->sum(fn($l) => (float) $l->monto_honorario), 2),
            'total_retencion'  => round($lineas->sum(fn($l) => (float) $l->retencion_isr), 2),
            'total_neto'       => round($lineas->sum(fn($l) => (float) $l->liquido), 2),
        ]);
    }

    private function rangoQuincena(int $anio, int $mes, int $q): array
    {
        if ($q === 1) {
            $inicio = Carbon::create($anio, $mes, 1)->toDateString();
            $fin    = Carbon::create($anio, $mes, 15)->toDateString();
        } else {
            $inicio = Carbon::create($anio, $mes, 16)->toDateString();
            $fin    = Carbon::create($anio, $mes)->endOfMonth()->toDateString();
        }
        return [$inicio, $fin];
    }
}
