<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\Asueto;
use App\Models\RRHH\Amonestacion;
use App\Models\RRHH\HorasExtrasSolicitud;
use App\Models\RRHH\AusenciaInjustificada;
use App\Models\RRHH\DiaSuspension;
use App\Models\RRHH\Incapacidad;
use App\Models\RRHH\Permiso;
use App\Models\RRHH\Planilla;
use App\Models\RRHH\PlanillaLinea;
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
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

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
        return $this->captureAndRespond($request, function () use ($request) {
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
            $suspensiones  = $this->getSuspensiones($empIds, $fechaInicio, $fechaFin);

            // Cargar asuetos del período y mapa de empleados con horario normal esos días
            $asuetos        = $this->getAsuetosEnRango($fechaInicio, $fechaFin);
            $horariosAsueto = $this->getHorariosNormalEnAsuetos($empIds, $asuetos);

            // Cargar horas extras aprobadas para esta quincena de pago
            $horasExtrasMap = $this->getHorasExtrasMap($empIds, $anio, $mes, $quincena);

            // Cargar órdenes de descuento activas del período
            $ordenesMap      = $this->getOrdenesActivasMap($empIds, $fechaInicio->toDateString(), $quincena);
            $bonificacionesMap = $this->getBonificacionesMap($empIds, $fechaInicio, $fechaFin);

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
                        $eid, $permisos, $incapacidades, $vacaciones, $ausencias, $suspensiones,
                        $fechaInicio, $hastaEfectivo
                    );
                    // Cap a diasQuincena: en meses con 31 días no se pagan días extra
                    $diasLab = min($diasQuincena, max(0, $diasEfectivos - $diasNoTrab));

                    // Órdenes de descuento y bonificaciones del empleado
                    $ordenes       = $ordenesMap[$eid]        ?? [];
                    $bonificEmp    = $bonificacionesMap[$eid] ?? [];

                    // Calcular
                    $resultado = $this->calc->calcularPlanillaEmpleado(
                        $salBase, (float) $diasLab, $diasQuincena, $ordenes, $bonificEmp
                    );

                    // Asuetos: empleados con horario 'normal' ese día cobran 100% extra
                    $geoDptoEmp    = $emp['geo_departamento_id'] ?? null;
                    $diasAsueto    = $this->contarAsuetosEmpleado(
                        (int) $eid, $geoDptoEmp, $asuetos, $horariosAsueto
                    );
                    $salarioAsueto = $diasAsueto > 0
                        ? round(($salBase / 30) * $diasAsueto, 2)
                        : 0.0;

                    $resultado['dias_asueto']    = $diasAsueto;
                    $resultado['salario_asueto'] = $salarioAsueto;
                    if ($salarioAsueto > 0) {
                        $resultado['salario_neto'] = round($resultado['salario_neto'] + $salarioAsueto, 2);
                    }

                    // Horas extras aprobadas para esta quincena (tarifa doble = salBase/240 × 2)
                    $horasExtrasEmp   = (float) ($horasExtrasMap[$eid] ?? 0);
                    $montoHorasExtras = $horasExtrasEmp > 0
                        ? round(($salBase / 240) * 2 * $horasExtrasEmp, 2)
                        : 0.0;

                    $resultado['horas_extras']       = round($horasExtrasEmp, 2);
                    $resultado['monto_horas_extras']  = $montoHorasExtras;
                    if ($montoHorasExtras > 0) {
                        $resultado['salario_neto'] = round($resultado['salario_neto'] + $montoHorasExtras, 2);
                    }

                    // Anotar días de suspensión en detalle para trazabilidad
                    $diasSusp = $suspensiones
                        ->filter(fn($s) => $s->amonestacion?->empleado_id === $eid)
                        ->count();
                    if ($diasSusp > 0) {
                        $detalle = json_decode($resultado['detalle_descuentos'] ?? '[]', true) ?: [];
                        $detalle[] = [
                            'concepto' => "Suspensión disciplinaria ({$diasSusp} día(s))",
                            'tipo'     => 'suspension',
                            'dias'     => $diasSusp,
                            'monto'    => 0, // el impacto ya está en dias_laborados reducidos
                        ];
                        $resultado['detalle_descuentos'] = json_encode($detalle);
                    }

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
        });
    }

    // ─── Aprobar ─────────────────────────────────────────────────────────────

    /**
     * PUT /api/rrhh/planillas/{id}/aprobar
     */
    public function aprobar(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $planilla = Planilla::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se puede aprobar una planilla en estado borrador.',
                ], 422);
            }

            $planilla->update(['estado' => 'aprobada']);

            $bonificIds = [];
            foreach ($planilla->lineas as $linea) {
                foreach ($linea->detalle_descuentos ?? [] as $item) {
                    if (($item['tipo'] ?? '') === 'bonificacion' && !empty($item['id'])) {
                        $bonificIds[] = (int) $item['id'];
                    }
                }
            }
            if (!empty($bonificIds)) {
                $estadoAplicadaId = DB::connection('pgsql')
                    ->table('estados_bonificacion')
                    ->where('nombre', 'Aplicada')
                    ->value('id');
                if ($estadoAplicadaId) {
                    DB::connection('pgsql')
                        ->table('bonificaciones')
                        ->whereIn('id', array_unique($bonificIds))
                        ->update(['estado_id' => $estadoAplicadaId, 'updated_at' => now()]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Planilla aprobada correctamente.',
                'data'    => $planilla->fresh(),
            ]);
        });
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
                'dias_asueto'               => (float) ($l->dias_asueto ?? 0),
                'salario_asueto'            => (float) ($l->salario_asueto ?? 0),
                'horas_extras'              => (float) ($l->horas_extras ?? 0),
                'monto_horas_extras'        => (float) ($l->monto_horas_extras ?? 0),
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
            ->leftJoin('sucursales as s', 's.id', '=', 'e.sucursal_id')
            ->where(function ($q) use ($desvinculadosIds) {
                $q->where('e.activo', true);
                if (!empty($desvinculadosIds)) {
                    $q->orWhereIn('e.id', $desvinculadosIds);
                }
            })
            ->select('e.id', 'e.salario_base', 's.geo_departamento_id');

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
     * Lee de la nueva tabla pgsql.ordenes_descuento con monto por quincena.
     */
    private function getOrdenesActivasMap(array $empIds, string $fecha, int $quincena = 1): array
    {
        if (empty($empIds)) return [];

        $montoCol = $quincena === 1 ? 'monto_q1' : 'monto_q2';

        $rows = DB::connection('pgsql')
            ->table('ordenes_descuento as od')
            ->join('estados_orden_descuento as e', 'e.id', '=', 'od.estado_id')
            ->join('acreedores as a', 'a.id', '=', 'od.acreedor_id')
            ->whereIn('od.empleado_id', $empIds)
            ->where('e.nombre', 'Activa')
            ->where('od.fecha_inicio', '<=', $fecha)
            ->where(function ($q) use ($fecha) {
                $q->whereNull('od.fecha_fin')
                  ->orWhere('od.fecha_fin', '>=', $fecha);
            })
            ->select(
                'od.empleado_id',
                'od.referencia',
                DB::raw("od.{$montoCol} as monto_quincenal"),
                'a.nombre as acreedor_nombre'
            )
            ->get();

        $map = [];
        foreach ($rows as $o) {
            $eid   = (int) $o->empleado_id;
            $monto = (float) $o->monto_quincenal;
            if ($monto <= 0) continue;
            if (!isset($map[$eid])) $map[$eid] = [];
            $map[$eid][] = [
                'concepto'        => $o->referencia ?? 'Orden de descuento',
                'monto_quincenal' => $monto,
                'acreedor_nombre' => $o->acreedor_nombre,
            ];
        }
        return $map;
    }

    /**
     * Retorna mapa empleado_id → array de bonificaciones aprobadas en el período.
     */
    private function getBonificacionesMap(array $empIds, Carbon $desde, Carbon $hasta): array
    {
        if (empty($empIds)) return [];

        $rows = DB::connection('pgsql')
            ->table('bonificaciones as b')
            ->join('estados_bonificacion as e', 'e.id', '=', 'b.estado_id')
            ->join('tipos_bonificacion as t', 't.id', '=', 'b.tipo_bonificacion_id')
            ->whereIn('b.empleado_id', $empIds)
            ->where('e.nombre', 'Aprobada')
            ->whereBetween('b.fecha_aplicar', [$desde->toDateString(), $hasta->toDateString()])
            ->select('b.id', 'b.empleado_id', 'b.monto', 'b.descripcion', 't.nombre as tipo_nombre')
            ->get();

        $map = [];
        foreach ($rows as $b) {
            $eid = (int) $b->empleado_id;
            if (!isset($map[$eid])) $map[$eid] = [];
            $map[$eid][] = [
                'id'       => $b->id,
                'concepto' => $b->descripcion ?: $b->tipo_nombre,
                'monto'    => (float) $b->monto,
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
        $permisos, $incapacidades, $vacaciones, $ausencias, $suspensiones,
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

        // Días de suspensión disciplinaria (sin goce de salario)
        $total += $suspensiones
            ->filter(fn($s) => $s->amonestacion?->empleado_id === $eid)
            ->count();

        return round($total, 2);
    }

    /**
     * Carga días de suspensión disciplinaria aprobados en el período.
     */
    private function getSuspensiones(array $empIds, Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        if (empty($empIds)) return collect([]);

        return DiaSuspension::with('amonestacion')
            ->whereHas('amonestacion', fn($q) => $q
                ->whereIn('empleado_id', $empIds)
                ->where('estado', 'aprobado')
                ->where('aplica_suspension', true)
                ->where('invalidada', false)
            )
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get();
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

    // ─── Boleta PDF ──────────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas/{id}/boleta/{empleadoId}
     * Genera el PDF de boleta de pago para un empleado en una planilla.
     */
    public function boletaPdf(int $id, int $empleadoId): \Illuminate\Http\Response
    {
        $planilla = Planilla::findOrFail($id);

        $linea = PlanillaLinea::where('planilla_id', $id)
            ->where('empleado_id', $empleadoId)
            ->firstOrFail();

        $empleado = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('cargos as c',        'c.id', '=', 'e.cargo_id')
            ->leftJoin('sucursales as s',     's.id', '=', 'e.sucursal_id')
            ->leftJoin('departamentos as d',  'd.id', '=', 'e.departamento_id')
            ->where('e.id', $empleadoId)
            ->select(
                'e.id', 'e.codigo', 'e.nombres', 'e.apellidos',
                'c.nombre as cargo_nombre',
                's.nombre as sucursal_nombre',
                'd.nombre as departamento_nombre'
            )
            ->first();

        abort_if(!$empleado, 404, 'Empleado no encontrado');

        // Adaptar para el blade (objetos con propiedades)
        $emp = (object)[
            'codigo'    => $empleado->codigo,
            'nombres'   => $empleado->nombres,
            'apellidos' => $empleado->apellidos,
            'cargo'     => $empleado->cargo_nombre ? (object)['nombre' => $empleado->cargo_nombre] : null,
            'sucursal'  => $empleado->sucursal_nombre ? (object)['nombre' => $empleado->sucursal_nombre] : null,
            'departamento' => $empleado->departamento_nombre ? (object)['nombre' => $empleado->departamento_nombre] : null,
        ];

        $meses = ['', 'enero','febrero','marzo','abril','mayo','junio',
                  'julio','agosto','septiembre','octubre','noviembre','diciembre'];

        // ── Logo como base64 ──────────────────────────────────────────────────
        $logoB64 = null;
        try {
            $logoData = @file_get_contents('https://cadejo-storage.s3.us-east-2.amazonaws.com/public/logo2.png');
            if ($logoData) $logoB64 = 'data:image/png;base64,' . base64_encode($logoData);
        } catch (\Throwable) {}

        // ── Documentos desde expediente (ya sincronizados por el script) ────
        $docs = DB::connection('rrhh')
            ->table('expediente_documentos')
            ->where('empleado_id', $empleadoId)
            ->whereIn('tipo', ['dui', 'pasaporte', 'carnet_residente', 'afp'])
            ->get()
            ->keyBy('tipo');

        $duiNumero = $docs->get('dui')?->numero
                  ?? $docs->get('pasaporte')?->numero
                  ?? $docs->get('carnet_residente')?->numero;

        $afpDoc    = $docs->get('afp');
        $afpNombre = $afpDoc?->entidad_emisora;
        $afpNumero = $afpDoc?->numero;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('rrhh.boleta', [
            'planilla'   => $planilla,
            'linea'      => $linea,
            'empleado'   => $emp,
            'mes_nombre' => $meses[$planilla->mes] ?? '',
            'logoB64'    => $logoB64,
            'duiNumero'  => $duiNumero,
            'afpNombre'  => $afpNombre,
            'afpNumero'  => $afpNumero,
        ])->setPaper('letter', 'portrait');

        $filename = "boleta_{$empleado->codigo}_Q{$planilla->quincena}_{$planilla->anio}_{$planilla->mes}.pdf";

        return $pdf->download($filename);
    }

    /**
     * Mapa empleado_id → total de horas_extras aprobadas cuya quincena de pago es la dada.
     * Tarifa doble: salario_mensual / 240 × 2 × horas (cálculo en el caller).
     */
    private function getHorasExtrasMap(array $empIds, int $anio, int $mes, int $quincena): array
    {
        if (empty($empIds)) return [];

        $map = [];
        HorasExtrasSolicitud::where('estado', 'aprobada')
            ->where('quincena_pago_anio', $anio)
            ->where('quincena_pago_mes', $mes)
            ->where('quincena_pago_num', $quincena)
            ->whereIn('empleado_id', $empIds)
            ->select('empleado_id', 'horas')
            ->get()
            ->each(function ($r) use (&$map) {
                $eid = (int) $r->empleado_id;
                $map[$eid] = ($map[$eid] ?? 0.0) + (float) $r->horas;
            });

        return $map;
    }

    /**
     * Todos los asuetos activos dentro de un rango de fechas (sin filtrar por sucursal).
     * El filtro por geo_departamento se hace por empleado en memoria.
     */
    private function getAsuetosEnRango(Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        return Asueto::where('activo', true)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get(['id', 'fecha', 'tipo', 'geo_departamento_id']);
    }

    /**
     * Mapa empleado_id → [fecha_string, ...] de los días de asueto donde el empleado
     * tiene horario tipo='normal' (es decir, estuvo en servicio ese día).
     */
    private function getHorariosNormalEnAsuetos(array $empIds, \Illuminate\Support\Collection $asuetos): array
    {
        if (empty($empIds) || $asuetos->isEmpty()) return [];

        $fechas = $asuetos
            ->map(fn ($a) => $a->fecha instanceof \Carbon\Carbon ? $a->fecha->toDateString() : substr((string) $a->fecha, 0, 10))
            ->unique()
            ->values()
            ->all();

        $map = [];
        DB::connection('pgsql')
            ->table('horarios_empleado')
            ->whereIn('empleado_id', $empIds)
            ->whereIn('fecha', $fechas)
            ->where('tipo', 'normal')
            ->select('empleado_id', 'fecha')
            ->get()
            ->each(function ($r) use (&$map) {
                $map[(int) $r->empleado_id][] = substr((string) $r->fecha, 0, 10);
            });

        return $map;
    }

    /**
     * Cuenta cuántos asuetos del período aplican a un empleado Y tiene horario normal.
     */
    private function contarAsuetosEmpleado(
        int $empId,
        ?int $geoDptoId,
        \Illuminate\Support\Collection $asuetos,
        array $horariosAsueto
    ): int {
        $fechasNormal = $horariosAsueto[$empId] ?? [];
        if (empty($fechasNormal)) return 0;

        $count = 0;
        foreach ($asuetos as $asueto) {
            $fecha = $asueto->fecha instanceof \Carbon\Carbon
                ? $asueto->fecha->toDateString()
                : substr((string) $asueto->fecha, 0, 10);

            $aplica = $asueto->tipo === 'nacional'
                || ($asueto->tipo === 'departamental'
                    && $geoDptoId
                    && (int) $asueto->geo_departamento_id === $geoDptoId);

            if ($aplica && in_array($fecha, $fechasNormal)) {
                $count++;
            }
        }
        return $count;
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
