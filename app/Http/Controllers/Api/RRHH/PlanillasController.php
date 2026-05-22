<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\AusenciaInjustificada;
use App\Models\RRHH\Incapacidad;
use App\Models\RRHH\Permiso;
use App\Models\RRHH\Planilla;
use App\Models\RRHH\PlanillaLinea;
use App\Models\RRHH\PlanillaOrdenDescuento;
use App\Models\RRHH\Vacacion;
use App\Services\RRHH\PlanillaCalculatorService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanillasController extends RRHHBaseController
{
    public function __construct(private PlanillaCalculatorService $calc) {}

    // ─── Listado ─────────────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas
     */
    public function index(Request $request): JsonResponse
    {
        $query = Planilla::query();

        if ($anio = $request->input('anio')) {
            $query->where('anio', (int) $anio);
        }
        if ($mes = $request->input('mes')) {
            $query->where('mes', (int) $mes);
        }
        if ($quincena = $request->input('quincena')) {
            $query->where('quincena', (int) $quincena);
        }
        if ($sucursalId = $request->input('sucursal_id')) {
            $query->where('sucursal_id', (int) $sucursalId);
        }

        $planillas = $query->orderByDesc('anio')
            ->orderByDesc('mes')
            ->orderByDesc('quincena')
            ->get();

        // Enriquecer con nombre de sucursal
        $sucursalIds = $planillas->pluck('sucursal_id')->filter()->unique()->values()->all();
        $sucursales  = [];
        if (!empty($sucursalIds)) {
            $sucursales = DB::connection('pgsql')
                ->table('sucursales')
                ->whereIn('id', $sucursalIds)
                ->pluck('nombre', 'id')
                ->all();
        }

        $data = $planillas->map(function ($p) use ($sucursales) {
            $arr = $p->toArray();
            $arr['sucursal_nombre']   = $sucursales[$p->sucursal_id] ?? null;
            $arr['lineas_count']      = $p->lineas()->count();
            return $arr;
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ─── Detalle ─────────────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas/{id}
     */
    public function show(int $id): JsonResponse
    {
        $planilla = Planilla::with('lineas')->findOrFail($id);

        // Cargar datos de empleados para las líneas
        $empIds = $planilla->lineas->pluck('empleado_id')->unique()->all();

        $empleados = $this->buildEmpleadosMap($empIds);

        $lineas = $planilla->lineas->map(function ($l) use ($empleados) {
            $arr = $l->toArray();
            $emp = $empleados[$l->empleado_id] ?? null;
            $arr['empleado_nombre']     = $emp ? trim($emp->nombres . ' ' . $emp->apellidos) : null;
            $arr['empleado_codigo']     = $emp?->codigo;
            $arr['cargo_nombre']        = $emp?->cargo_nombre;
            $arr['sucursal_nombre']     = $emp?->sucursal_nombre;
            $arr['departamento_nombre'] = $emp?->departamento_nombre;
            $arr['sin_salario']         = ($arr['salario_base'] ?? 0) == 0;
            $arr['jefe_de_deptos']      = $emp?->jefe_de_deptos ?? [];
            $arr['jefe_en_sucs']        = $emp?->jefe_en_sucs   ?? [];
            return $arr;
        })->sortBy(fn($l) => $l['empleado_codigo'] ?? '')->values();

        $sucursalNombre = null;
        if ($planilla->sucursal_id) {
            $sucursalNombre = DB::connection('pgsql')
                ->table('sucursales')
                ->where('id', $planilla->sucursal_id)
                ->value('nombre');
        }

        return response()->json([
            'success' => true,
            'data'    => array_merge($planilla->toArray(), [
                'lineas'          => $lineas,
                'sucursal_nombre' => $sucursalNombre,
            ]),
        ]);
    }

    // ─── Generación / Regeneración ────────────────────────────────────────────

    /**
     * POST /api/rrhh/planillas/generar
     */
    public function generar(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'anio'        => 'required|integer|min:2020|max:2099',
                'mes'         => 'required|integer|min:1|max:12',
                'quincena'    => 'required|integer|in:1,2',
                'sucursal_id' => 'nullable|integer',
            ]);

            $anio       = (int) $validated['anio'];
            $mes        = (int) $validated['mes'];
            $quincena   = (int) $validated['quincena'];
            $sucursalId = isset($validated['sucursal_id']) ? (int) $validated['sucursal_id'] : null;

            // Buscar o crear la planilla
            $planilla = Planilla::where('anio', $anio)
                ->where('mes', $mes)
                ->where('quincena', $quincena)
                ->where('sucursal_id', $sucursalId)
                ->first();

            if ($planilla && $planilla->estado === 'pagada') {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede regenerar una planilla con estado pagada.',
                ], 422);
            }

            // Calcular rango de fechas de la quincena
            [$fechaInicio, $fechaFin] = $this->calc->rangoQuincena($anio, $mes, $quincena);
            $diasQuincena = PlanillaCalculatorService::DIAS_QUINCENA; // Siempre 15 (30 días/mes)

            if (!$planilla) {
                $planilla = Planilla::create([
                    'anio'        => $anio,
                    'mes'         => $mes,
                    'quincena'    => $quincena,
                    'fecha_inicio'=> $fechaInicio->toDateString(),
                    'fecha_fin'   => $fechaFin->toDateString(),
                    'sucursal_id' => $sucursalId,
                    'estado'      => 'borrador',
                    'creado_por'  => Auth::id(),
                ]);
            } else {
                $planilla->update([
                    'fecha_inicio' => $fechaInicio->toDateString(),
                    'fecha_fin'    => $fechaFin->toDateString(),
                    'estado'       => 'borrador',
                ]);
            }

            // Cargar empleados activos + desvinculados durante el período
            ['empleados' => $empleados, 'desvinculados' => $desvinculados]
                = $this->getEmpleadosParaPlanilla($sucursalId, $fechaInicio, $fechaFin);
            $empIds = array_column($empleados, 'id');

            // Cargar eventos del período (misma lógica que ReportesRRHHController)
            $permisos      = $this->getPermisos($empIds, $fechaInicio, $fechaFin);
            $incapacidades = $this->getIncapacidades($empIds, $fechaInicio, $fechaFin);
            $vacaciones    = $this->getVacaciones($empIds, $fechaInicio, $fechaFin);
            $ausencias     = $this->getAusencias($empIds, $fechaInicio, $fechaFin);

            // Cargar órdenes de descuento activas del período
            $ordenesMap = $this->getOrdenesActivasMap($empIds, $fechaInicio->toDateString());

            $lineasCalc = [];

            DB::connection('rrhh')->beginTransaction();
            try {
                // Borrar líneas anteriores de esta planilla
                PlanillaLinea::where('planilla_id', $planilla->id)->delete();

                foreach ($empleados as $emp) {
                    $eid     = (int) $emp['id'];
                    $salBase = (float) ($emp['salario_base'] ?? 0);

                    // Para desvinculados: acotar el período hasta su fecha efectiva
                    $desvinc = $desvinculados->get($eid);
                    if ($desvinc) {
                        $fechaTerm     = Carbon::parse($desvinc->fecha_efectiva);
                        $hastaEfectivo = $fechaTerm->lessThan($fechaFin) ? $fechaTerm : $fechaFin;
                        // Días proporcionales al período efectivo, máx 15 (estándar 30 días/mes)
                        $diasEfectivos = min($diasQuincena, max(1, $fechaInicio->diffInDays($hastaEfectivo) + 1));
                    } else {
                        $hastaEfectivo = $fechaFin;
                        $diasEfectivos = $diasQuincena; // 15
                    }

                    // Días no trabajados dentro del período efectivo del empleado
                    $diasNoTrab = $this->calcDiasNoTrabajados(
                        $eid, $permisos, $incapacidades, $vacaciones, $ausencias,
                        $fechaInicio, $hastaEfectivo
                    );
                    // Cap a diasQuincena: en meses con 31 días no se pagan días extra
                    $diasLab = min($diasQuincena, max(0, $diasEfectivos - $diasNoTrab));

                    // Órdenes de descuento del empleado
                    $ordenes = $ordenesMap[$eid] ?? [];

                    // Calcular
                    $resultado = $this->calc->calcularPlanillaEmpleado(
                        $salBase, (float) $diasLab, $diasQuincena, $ordenes
                    );

                    $linea = PlanillaLinea::create(array_merge(
                        [
                            'planilla_id' => $planilla->id,
                            'empleado_id' => $eid,
                        ],
                        $resultado
                    ));

                    $lineasCalc[] = $resultado;
                }

                // Actualizar totales de la planilla
                $totales = $this->calc->calcularTotalesPlanilla($lineasCalc);
                $planilla->update($totales);

                DB::connection('rrhh')->commit();
            } catch (\Throwable $e) {
                DB::connection('rrhh')->rollBack();
                throw $e;
            }

            // Retornar planilla completa con líneas
            return $this->show($planilla->id);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?? 'Error de validación.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('PlanillasController@generar: ' . $e->getMessage(), [
                'user'  => Auth::user()?->email,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Aprobar ─────────────────────────────────────────────────────────────

    /**
     * PUT /api/rrhh/planillas/{id}/aprobar
     */
    public function aprobar(int $id): JsonResponse
    {
        $planilla = Planilla::findOrFail($id);

        if ($planilla->estado !== 'borrador') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se puede aprobar una planilla en estado borrador.',
            ], 422);
        }

        $planilla->update(['estado' => 'aprobada']);

        return response()->json([
            'success' => true,
            'message' => 'Planilla aprobada correctamente.',
            'data'    => $planilla->fresh(),
        ]);
    }

    // ─── Exportar (data para Excel) ───────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas/{id}/exportar
     */
    public function exportar(int $id): JsonResponse
    {
        $planilla = Planilla::with('lineas')->findOrFail($id);
        $empIds   = $planilla->lineas->pluck('empleado_id')->unique()->all();
        $empleados = $this->buildEmpleadosMap($empIds);

        $sucursalNombre = null;
        if ($planilla->sucursal_id) {
            $sucursalNombre = DB::connection('pgsql')
                ->table('sucursales')
                ->where('id', $planilla->sucursal_id)
                ->value('nombre');
        }

        $filas = $planilla->lineas->map(function ($l) use ($empleados) {
            $emp = $empleados[$l->empleado_id] ?? null;
            return [
                'codigo'                    => $emp?->codigo ?? '',
                'nombres'                   => $emp ? trim($emp->nombres . ' ' . $emp->apellidos) : '',
                'departamento_sucursal'     => $emp?->sucursal_nombre ?? $emp?->departamento_nombre ?? '',
                'cargo'                     => $emp?->cargo_nombre ?? '',
                'dias_laborados'            => (float) $l->dias_laborados,
                'salario_base'              => (float) $l->salario_base,
                'salario_proporcional'      => (float) $l->salario_proporcional,
                'afp_empleado'              => (float) $l->afp_empleado,
                'isss_empleado'             => (float) $l->isss_empleado,
                'renta'                     => (float) $l->renta,
                'otros_descuentos'          => (float) $l->otros_descuentos,
                'total_descuentos'          => (float) $l->total_descuentos_empleado,
                'salario_neto'              => (float) $l->salario_neto,
                'afp_patronal'              => (float) $l->afp_patronal,
                'isss_patronal'             => (float) $l->isss_patronal,
                'insaforp'                  => (float) $l->insaforp_patronal,
                'total_patronal'            => (float) $l->total_patronal,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'planilla'        => array_merge($planilla->toArray(), ['sucursal_nombre' => $sucursalNombre]),
                'filas'           => $filas,
                'totales'         => [
                    'total_salarios'   => (float) $planilla->total_salarios,
                    'total_descuentos' => (float) $planilla->total_descuentos,
                    'total_patronal'   => (float) $planilla->total_patronal,
                    'total_neto'       => (float) $planilla->total_neto,
                ],
            ],
        ]);
    }

    // ─── Helpers privados ────────────────────────────────────────────────────

    private function getEmpleadosParaPlanilla(?int $sucursalId, Carbon $fechaInicio, Carbon $fechaFin): array
    {
        // Empleados desvinculados con fecha efectiva dentro del período
        $desvinculados = DB::connection('rrhh')
            ->table('desvinculaciones')
            ->where('estado', 'aprobado')
            ->where('fecha_efectiva', '>=', $fechaInicio->toDateString())
            ->where('fecha_efectiva', '<=', $fechaFin->toDateString())
            ->select('empleado_id', 'fecha_efectiva')
            ->get()
            ->keyBy('empleado_id');

        $desvinculadosIds = $desvinculados->keys()->all();

        $query = DB::connection('pgsql')
            ->table('empleados as e')
            ->where(function ($q) use ($desvinculadosIds) {
                $q->where('e.activo', true);
                if (!empty($desvinculadosIds)) {
                    $q->orWhereIn('e.id', $desvinculadosIds);
                }
            })
            ->select('e.id', 'e.salario_base');

        if ($sucursalId) {
            $query->where('e.sucursal_id', $sucursalId);
        }

        return [
            'empleados'     => $query->get()->map(fn($r) => (array) $r)->all(),
            'desvinculados' => $desvinculados,
        ];
    }

    private function getPermisos(array $empIds, Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        return Permiso::with('tipoPermiso')
            ->whereIn('empleado_id', $empIds)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get();
    }

    private function getIncapacidades(array $empIds, Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        return Incapacidad::whereIn('empleado_id', $empIds)
            ->where('fecha_inicio', '<=', $hasta->toDateString())
            ->where('fecha_fin',    '>=', $desde->toDateString())
            ->get();
    }

    private function getVacaciones(array $empIds, Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        return Vacacion::whereIn('empleado_id', $empIds)
            ->where('fecha_inicio', '<=', $hasta->toDateString())
            ->where('fecha_fin',    '>=', $desde->toDateString())
            ->get();
    }

    private function getAusencias(array $empIds, Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        return AusenciaInjustificada::whereIn('empleado_id', $empIds)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get();
    }

    /**
     * Retorna mapa empleado_id → array de órdenes activas.
     */
    private function getOrdenesActivasMap(array $empIds, string $fecha): array
    {
        if (empty($empIds)) return [];

        $ordenes = PlanillaOrdenDescuento::with('acreedor')
            ->whereIn('empleado_id', $empIds)
            ->where('activo', true)
            ->where('fecha_inicio', '<=', $fecha)
            ->where(function ($q) use ($fecha) {
                $q->whereNull('fecha_fin')
                  ->orWhere('fecha_fin', '>=', $fecha);
            })
            ->get();

        $map = [];
        foreach ($ordenes as $o) {
            $eid = (int) $o->empleado_id;
            if (!isset($map[$eid])) $map[$eid] = [];
            $map[$eid][] = [
                'concepto'        => $o->concepto,
                'monto_quincenal' => (float) $o->monto_quincenal,
                'acreedor_nombre' => $o->acreedor?->nombre,
            ];
        }
        return $map;
    }

    /**
     * Calcula días no trabajados (con descuento) para un empleado en la quincena.
     * Misma lógica que ReportesRRHHController::calcDiasNoTrabajadosDescuentos.
     */
    private function calcDiasNoTrabajados(
        int $eid,
        $permisos, $incapacidades, $vacaciones, $ausencias,
        Carbon $desde, Carbon $hasta
    ): float {
        $total = 0.0;

        foreach ($permisos->where('empleado_id', $eid) as $p) {
            if ($p->tipoPermiso && $p->tipoPermiso->categoria === 'sin_goce') {
                $total += (float) ($p->dias ?? ($p->horas_solicitadas ? $p->horas_solicitadas / 8 : 0));
            }
        }

        foreach ($incapacidades->where('empleado_id', $eid) as $inc) {
            $diasEnQ = $this->diasSolapados($inc->fecha_inicio, $inc->fecha_fin, $desde, $hasta);
            if ($inc->tipo_institucion === 'privada') {
                $total += $diasEnQ;
            } elseif ($inc->tipo_institucion === 'isss') {
                $total += max(0, min((int) $inc->dias - 3, $diasEnQ));
            }
        }

        foreach ($vacaciones->where('empleado_id', $eid) as $v) {
            $total += $this->diasSolapados($v->fecha_inicio, $v->fecha_fin, $desde, $hasta);
        }

        foreach ($ausencias->where('empleado_id', $eid) as $a) {
            $total += 1;
        }

        return round($total, 2);
    }

    private function diasSolapados($inicio, $fin, Carbon $desde, Carbon $hasta): int
    {
        $start = max(Carbon::parse($inicio), $desde);
        $end   = min(Carbon::parse($fin), $hasta);
        return max(0, $start->diffInDays($end) + 1);
    }

    private function buildEmpleadosMap(array $empIds): \Illuminate\Support\Collection
    {
        if (empty($empIds)) return collect([]);

        $empleados = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('cargos as c', 'e.cargo_id', '=', 'c.id')
            ->leftJoin('sucursales as s', 'e.sucursal_id', '=', 's.id')
            ->leftJoin('departamentos as d', 'e.departamento_id', '=', 'd.id')
            ->whereIn('e.id', $empIds)
            ->select(
                'e.id', 'e.codigo', 'e.nombres', 'e.apellidos',
                'c.nombre as cargo_nombre',
                's.nombre as sucursal_nombre',
                'd.nombre as departamento_nombre'
            )
            ->get()
            ->keyBy('id');

        // Departamentos en los que cada empleado figura como jefe
        $jefaturasRaw = DB::connection('pgsql')
            ->table('departamentos as d')
            ->leftJoin('sucursales as s', 'd.sucursal_id', '=', 's.id')
            ->whereIn('d.jefe_empleado_id', $empIds)
            ->whereNotNull('d.jefe_empleado_id')
            ->select('d.jefe_empleado_id', 'd.nombre as depto_nombre', 's.nombre as suc_nombre')
            ->get();

        $jefaturasMap = [];
        foreach ($jefaturasRaw as $j) {
            $eid = $j->jefe_empleado_id;
            if (!isset($jefaturasMap[$eid])) {
                $jefaturasMap[$eid] = ['deptos' => [], 'sucs' => []];
            }
            if ($j->depto_nombre) $jefaturasMap[$eid]['deptos'][] = $j->depto_nombre;
            if ($j->suc_nombre && !in_array($j->suc_nombre, $jefaturasMap[$eid]['sucs'])) {
                $jefaturasMap[$eid]['sucs'][] = $j->suc_nombre;
            }
        }

        return $empleados->map(function ($emp) use ($jefaturasMap) {
            $eid = $emp->id;
            $emp->jefe_de_deptos = $jefaturasMap[$eid]['deptos'] ?? [];
            $emp->jefe_en_sucs   = $jefaturasMap[$eid]['sucs']   ?? [];
            return $emp;
        });
    }

    /**
     * Retorna mapa de tipo de sucursal por sucursal_id.
     * (Heredado de ReportesRRHHController para consistencia)
     */
    private function getSucursalTiposMap(array $sucursalIds): array
    {
        if (empty($sucursalIds)) return [];

        return DB::connection('pgsql')
            ->table('sucursales as s')
            ->join('tipos_sucursal as ts', 'ts.id', '=', 's.tipo_sucursal_id')
            ->whereIn('s.id', $sucursalIds)
            ->pluck('ts.codigo', 's.id')
            ->all();
    }
}
