<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\Asueto;
use App\Models\RRHH\Amonestacion;
use App\Models\RRHH\AusenciaInjustificada;
use App\Models\RRHH\DiaSuspension;
use App\Models\RRHH\Incapacidad;
use App\Models\RRHH\Permiso;
use App\Models\RRHH\Planilla;
use App\Models\RRHH\PlanillaLinea;
use App\Models\RRHH\PlanillaMovimiento;
use App\Models\RRHH\Vacacion;
use App\Services\RRHH\PlanillaCalculatorService;
use App\Services\RRHH\PlanillaSalariosService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanillasController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    public function __construct(
        private PlanillaCalculatorService $calc,
        private PlanillaSalariosService $svc,
    ) {}

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
            $arr['lineas_count']      = $p->lineas()->where('estado', 'activo')->count();
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
            $arr['empleado_apellidos']  = $emp?->apellidos;
            $arr['empleado_nombres']    = $emp?->nombres;
            $arr['empleado_codigo']     = $emp?->codigo;
            $arr['cargo_nombre']        = $emp?->cargo_nombre;
            $arr['sucursal_nombre']     = $emp?->sucursal_nombre;
            $arr['departamento_nombre'] = $emp?->departamento_nombre;
            $arr['sin_salario']         = ($arr['salario_base'] ?? 0) == 0;
            $arr['jefe_de_deptos']      = $emp?->jefe_de_deptos ?? [];
            $arr['jefe_en_sucs']        = $emp?->jefe_en_sucs   ?? [];
            return $arr;
        })->sortBy(fn($l) => strtolower($l['empleado_apellidos'] ?? ''))->values();

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
                'anio'          => 'required|integer|min:2020|max:2099',
                'mes'           => 'required|integer|min:1|max:12',
                'quincena'      => 'required|integer|in:1,2',
                'sucursal_id'   => 'nullable|integer',
                'tipo_planilla' => 'nullable|string|exists:tipo_planilla,slug',
            ]);

            $anio          = (int) $validated['anio'];
            $mes           = (int) $validated['mes'];
            $quincena      = (int) $validated['quincena'];
            $sucursalId    = isset($validated['sucursal_id']) ? (int) $validated['sucursal_id'] : null;
            $tipoPlanilla  = $validated['tipo_planilla'] ?? 'salarios';

            // Buscar o crear la planilla
            $planilla = Planilla::where('anio', $anio)
                ->where('mes', $mes)
                ->where('quincena', $quincena)
                ->where('sucursal_id', $sucursalId)
                ->first();

            if ($planilla && in_array($planilla->estado, ['pagada', 'cerrada', 'historica'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede regenerar una planilla con estado ' . $planilla->estado . '.',
                ], 422);
            }

            // Calcular rango de fechas de la quincena
            [$fechaInicio, $fechaFin] = $this->calc->rangoQuincena($anio, $mes, $quincena);
            $diasQuincena = PlanillaCalculatorService::DIAS_QUINCENA; // Siempre 15 (30 días/mes)

            if (!$planilla) {
                $planilla = Planilla::create([
                    'anio'          => $anio,
                    'mes'           => $mes,
                    'quincena'      => $quincena,
                    'fecha_inicio'  => $fechaInicio->toDateString(),
                    'fecha_fin'     => $fechaFin->toDateString(),
                    'sucursal_id'   => $sucursalId,
                    'tipo_planilla' => $tipoPlanilla,
                    'estado'        => 'borrador',
                    'creado_por'    => Auth::id(),
                ]);
            } else {
                $planilla->update([
                    'fecha_inicio'  => $fechaInicio->toDateString(),
                    'fecha_fin'     => $fechaFin->toDateString(),
                    'tipo_planilla' => $tipoPlanilla,
                    'estado'        => 'borrador',
                ]);
            }

            // Cargar empleados activos + desvinculados durante el período, filtrados por tipo_planilla
            ['empleados' => $empleados, 'desvinculados' => $desvinculados]
                = $this->svc->resolverEmpleados($sucursalId, $fechaInicio, $fechaFin, $tipoPlanilla);

            // Excluir empleados bloqueados explícitamente para esta planilla
            $exclusionesIds = DB::connection('rrhh')
                ->table('planilla_exclusiones')
                ->where('planilla_id', $planilla->id)
                ->pluck('empleado_id')
                ->toArray();
            if (!empty($exclusionesIds)) {
                $empleados = array_values(array_filter($empleados, fn($e) => !in_array((int) $e['id'], $exclusionesIds)));
            }

            $empIds = array_column($empleados, 'id');

            $ctx = $this->svc->cargarContexto($empIds, $anio, $mes, $quincena, $fechaInicio, $fechaFin);

            $lineasCalc = [];

            DB::connection('rrhh')->beginTransaction();
            try {
                // Solo borrar líneas activas; las excluidas se conservan
                PlanillaLinea::where('planilla_id', $planilla->id)->where('estado', 'activo')->delete();

                foreach ($empleados as $emp) {
                    $eid = (int) $emp['id'];

                    $desvinc = $desvinculados->get($eid);
                    $hastaEfectivo = $desvinc
                        ? (Carbon::parse($desvinc->fecha_efectiva)->lessThan($fechaFin)
                            ? Carbon::parse($desvinc->fecha_efectiva)
                            : $fechaFin)
                        : null;

                    $resultado = $this->svc->calcularLinea($emp, $ctx, $fechaInicio, $fechaFin, $hastaEfectivo);

                    PlanillaLinea::create(array_merge(['planilla_id' => $planilla->id, 'empleado_id' => $eid], $resultado));
                    $lineasCalc[] = $resultado;
                }

                $totales = $this->calc->calcularTotalesPlanilla($lineasCalc);
                $planilla->update($totales);

                $this->svc->snapshotMovimientos($planilla->id, $empleados, $ctx, $fechaInicio, $fechaFin, $exclusionesIds);

                DB::connection('rrhh')->commit();
            } catch (\Throwable $e) {
                DB::connection('rrhh')->rollBack();
                throw $e;
            }

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
                'message' => 'Planilla finalizada correctamente.',
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
        $planilla = Planilla::with(['lineas' => fn($q) => $q->where('estado', 'activo')])->findOrFail($id);
        $empIds   = $planilla->lineas->pluck('empleado_id')->unique()->all();
        $empleados = $this->buildEmpleadosMap($empIds);

        $sucursalNombre = null;
        if ($planilla->sucursal_id) {
            $sucursalNombre = DB::connection('pgsql')
                ->table('sucursales')
                ->where('id', $planilla->sucursal_id)
                ->value('nombre');
        }

        // Cuentas bancarias principales (rrhh_db)
        $cuentasMap = DB::connection('rrhh')
            ->table('expediente_cuentas_banco')
            ->whereIn('empleado_id', $empIds)
            ->where('es_principal', true)
            ->select('empleado_id', 'banco', 'numero_cuenta')
            ->get()
            ->keyBy('empleado_id');

        // AFP por empleado (rrhh_db) — tipo='afp', entidad_emisora = 'AFP CONFIA' | 'AFP CRECER'
        $afpMap = DB::connection('rrhh')
            ->table('expediente_documentos')
            ->whereIn('empleado_id', $empIds)
            ->where('tipo', 'afp')
            ->select('empleado_id', 'entidad_emisora')
            ->get()
            ->keyBy('empleado_id');

        $filas = $planilla->lineas->map(function ($l) use ($empleados, $cuentasMap, $afpMap) {
            $emp    = $empleados[$l->empleado_id] ?? null;
            $cuenta = $cuentasMap[$l->empleado_id] ?? null;
            $afpDoc = $afpMap[$l->empleado_id] ?? null;

            $salProp    = (float) $l->salario_proporcional;
            $salAsueto  = (float) ($l->salario_asueto ?? 0);
            $montoHE    = (float) ($l->monto_horas_extras ?? 0);
            $montoNoct  = (float) ($l->monto_nocturnidades ?? 0);
            $bonif      = (float) ($l->bonificaciones ?? 0);
            $comisiones = (float) ($l->comisiones ?? 0);
            $vac        = (float) ($l->vacaciones ?? 0);

            // AFP: desglosar según entidad registrada en expediente
            $afpEntidad = $afpDoc ? strtoupper($afpDoc->entidad_emisora ?? '') : '';
            $afpTotal   = (float) $l->afp_empleado;
            $afpCrecer  = str_contains($afpEntidad, 'CRECER') ? $afpTotal : 0.0;
            $afpConfia  = str_contains($afpEntidad, 'CONFIA') || str_contains($afpEntidad, 'CONFÍA') ? $afpTotal : 0.0;

            // Observaciones — alertas de datos faltantes o inconsistencias
            $obs = [];
            if (!$cuenta) {
                $obs[] = 'Sin cuenta BAC configurada';
            }
            if (!$afpDoc || $afpEntidad === '') {
                $obs[] = 'Sin AFP registrada';
            } elseif ($afpCrecer === 0.0 && $afpConfia === 0.0 && $afpTotal > 0) {
                $obs[] = 'AFP no identificada: ' . ($afpDoc->entidad_emisora ?? '');
            }
            if ((float) $l->salario_base <= 0) {
                $obs[] = 'Sin salario base configurado';
            }

            return [
                'codigo'                    => $emp?->codigo ?? '',
                'nombres'                   => $emp?->nombres ?? '',
                'apellidos'                 => $emp?->apellidos ?? '',
                'departamento_sucursal'     => $emp?->sucursal_nombre ?? $emp?->departamento_nombre ?? '',
                'cargo'                     => $emp?->cargo_nombre ?? '',
                'dias_laborados'            => (float) $l->dias_laborados,
                'horas_laboradas'           => round((float) $l->dias_laborados * 8, 2),
                'salario_base'              => (float) $l->salario_base,
                'salario_proporcional'      => $salProp,
                'dias_asueto'               => (float) ($l->dias_asueto ?? 0),
                'salario_asueto'            => $salAsueto,
                'horas_extras'              => (float) ($l->horas_extras ?? 0),
                'monto_horas_extras'        => $montoHE,
                'horas_nocturnas'           => (float) ($l->horas_nocturnas ?? 0),
                'monto_nocturnidades'       => $montoNoct,
                'bonificaciones'            => $bonif,
                'comisiones'                => $comisiones,
                'vacaciones'                => $vac,
                'total_ingresos'            => round($salProp + $salAsueto + $montoHE + $montoNoct + $bonif + $comisiones + $vac, 2),
                'afp_crecer'                => $afpCrecer,
                'afp_confia'                => $afpConfia,
                'afp_empleado'              => $afpTotal,
                'isss_empleado'             => (float) $l->isss_empleado,
                'renta'                     => (float) $l->renta,
                'otros_descuentos'          => (float) $l->otros_descuentos,
                'total_descuentos'          => (float) $l->total_descuentos_empleado,
                'salario_neto'              => (float) $l->salario_neto,
                'afp_patronal'              => (float) $l->afp_patronal,
                'isss_patronal'             => (float) $l->isss_patronal,
                'insaforp'                  => (float) $l->insaforp_patronal,
                'total_patronal'            => (float) $l->total_patronal,
                'forma_pago'                => 'Transferencia',
                'cuenta_banco'              => $cuenta?->banco ?? '',
                'numero_cuenta'             => $cuenta?->numero_cuenta ?? '',
                'observaciones'             => implode(' | ', $obs),
            ];
        })->sortBy(fn($f) => strtolower($f['apellidos'] ?? ''))->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'planilla'        => array_merge($planilla->toArray(), ['sucursal_nombre' => $sucursalNombre]),
                'filas'           => $filas,
                'totales'         => [
                    'total_salarios'        => (float) $planilla->total_salarios,
                    'total_salario_asueto'  => $filas->sum('salario_asueto'),
                    'total_horas_extras'    => $filas->sum('monto_horas_extras'),
                    'total_nocturnidades'   => $filas->sum('monto_nocturnidades'),
                    'total_bonificaciones'  => $filas->sum('bonificaciones'),
                    'total_comisiones'      => $filas->sum('comisiones'),
                    'total_vacaciones'      => $filas->sum('vacaciones'),
                    'total_ingresos'        => $filas->sum('total_ingresos'),
                    'total_afp_empleado'    => $filas->sum('afp_empleado'),
                    'total_afp_crecer'      => $filas->sum('afp_crecer'),
                    'total_afp_confia'      => $filas->sum('afp_confia'),
                    'total_isss_empleado'   => $filas->sum('isss_empleado'),
                    'total_renta'           => $filas->sum('renta'),
                    'total_otros_descuentos'=> $filas->sum('otros_descuentos'),
                    'total_descuentos'      => (float) $planilla->total_descuentos,
                    'total_patronal'        => (float) $planilla->total_patronal,
                    'total_afp_patronal'    => $filas->sum('afp_patronal'),
                    'total_isss_patronal'   => $filas->sum('isss_patronal'),
                    'total_insaforp'        => $filas->sum('insaforp'),
                    'total_neto'            => (float) $planilla->total_neto,
                ],
            ],
        ]);
    }

    // ─── Helpers privados ────────────────────────────────────────────────────

    private function getEmpleadosParaPlanilla(?int $sucursalId, Carbon $fechaInicio, Carbon $fechaFin, string $tipoPlanilla = 'salarios'): array
    {
        $transicionandoMap = collect(); // empleado_id => primer día en salarios (para prueba transitional)

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
            ->select('e.id', 'e.salario_base', 's.geo_departamento_id', 'e.es_pensionado', 'e.fecha_ingreso');

        $fechaInicioStr = $fechaInicio->toDateString();
        $fechaFinStr    = $fechaFin->toDateString();

        if ($tipoPlanilla === 'honorarios') {
            // Honorarios: solo empleados marcados explícitamente con tipo honorarios vigente.
            $honorariosIds = DB::table('empleado_tipo_planilla as etp')
                ->join('tipo_planilla as tp', 'tp.id', '=', 'etp.tipo_planilla_id')
                ->where('tp.slug', 'honorarios')
                ->where('etp.fecha_inicio', '<=', $fechaFinStr)
                ->where(function ($q) use ($fechaInicioStr) {
                    $q->whereNull('etp.fecha_fin')
                      ->orWhere('etp.fecha_fin', '>=', $fechaInicioStr);
                })
                ->pluck('etp.empleado_id');

            $query->whereIn('e.id', $honorariosIds);
        } else {
            // Resto de tipos: solo empleados con ese tipo asignado y vigente en el período.
            // Excluir empleados cuya prueba aún no termina antes del fin del período
            // (los que salen de prueba a mitad de quincena sí entran, con días proporcionales).
            $pruebas = DB::connection('rrhh')
                ->table('periodos_prueba')
                ->where('estado', 'en_prueba')
                ->select('empleado_id', 'fecha_fin_estimada')
                ->get()
                ->keyBy('empleado_id');

            // Excluir solo los que siguen en prueba pasado el fin del período
            $excluirPruebaIds = $pruebas
                ->filter(fn($p) => $p->fecha_fin_estimada >= $fechaFinStr)
                ->keys()->toArray();

            // Empleados que salen de prueba DURANTE este período → incluir con inicio proporcional
            $transicionandoMap = $pruebas
                ->filter(fn($p) => $p->fecha_fin_estimada >= $fechaInicioStr && $p->fecha_fin_estimada < $fechaFinStr)
                ->mapWithKeys(fn($p) => [
                    (int) $p->empleado_id => Carbon::parse($p->fecha_fin_estimada)->addDay()->toDateString()
                ]);

            $query->join('empleado_tipo_planilla as etp', 'etp.empleado_id', '=', 'e.id')
                  ->join('tipo_planilla as tp', function ($join) use ($tipoPlanilla) {
                      $join->on('tp.id', '=', 'etp.tipo_planilla_id')
                           ->where('tp.slug', '=', $tipoPlanilla);
                  })
                  ->where('etp.fecha_inicio', '<=', $fechaFinStr)
                  ->where(function ($q) use ($fechaInicioStr) {
                      $q->whereNull('etp.fecha_fin')
                        ->orWhere('etp.fecha_fin', '>=', $fechaInicioStr);
                  });

            if (!empty($excluirPruebaIds)) {
                $query->whereNotIn('e.id', $excluirPruebaIds);
            }
        }

        if ($sucursalId) {
            $query->where('e.sucursal_id', $sucursalId);
        }

        $empleados = $query->get()->map(function ($r) use ($transicionandoMap) {
            $r = (array) $r;
            // Para empleados que salen de prueba a mitad de quincena, señalar su primer día en salarios
            if (isset($transicionandoMap[(int) $r['id']])) {
                $r['fecha_ingreso_efectiva'] = $transicionandoMap[(int) $r['id']];
            }
            return $r;
        })->all();

        return [
            'empleados'     => $empleados,
            'desvinculados' => $desvinculados,
        ];
    }

    private function getPermisos(array $empIds, Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        return Permiso::with('tipoPermiso')
            ->whereIn('empleado_id', $empIds)
            ->where('estado', 'aprobado')
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
            ->where('estado', 'aprobado')
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
                'od.id as orden_id',
                'od.acreedor_id',
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
                'acreedor_id'     => (int) $o->acreedor_id,
                'orden_id'        => (int) $o->orden_id,
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

        // Las vacaciones NO descuentan del salario proporcional en la planilla:
        // el empleado cobra normalmente + la prima del 30% (Art. 177 CT-SV).

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
     * Mapa empleado_id → horas extra calculadas desde horarios_empleado del período
     * de TRABAJO correspondiente a la quincena de pago (pago vencido).
     *
     * Regla de pago vencido (igual que nocturnidades):
     *   pago Q1 → trabajo Q2 del mes anterior
     *   pago Q2 → trabajo Q1 del mismo mes
     *
     * Exceso por día: >8h en jornada diurna, >7h si la jornada es nocturna (>4h noct).
     * Arts. 161-162 CT-SV. Tarifa doble aplicada en el caller.
     */
    private function getHorasExtrasMap(array $empIds, int $anioP, int $mesP, int $quincenaP): array
    {
        if (empty($empIds)) return [];

        // Período de trabajo (quincena anterior al pago)
        if ($quincenaP === 1) {
            $mesTrab  = $mesP === 1 ? 12 : $mesP - 1;
            $anioTrab = $mesP === 1 ? $anioP - 1 : $anioP;
            $desde    = Carbon::create($anioTrab, $mesTrab, 16);
            $hasta    = Carbon::create($anioTrab, $mesTrab)->endOfMonth()->startOfDay();
        } else {
            $desde = Carbon::create($anioP, $mesP, 1);
            $hasta = Carbon::create($anioP, $mesP, 15);
        }

        $horarios = DB::connection('pgsql')
            ->table('horarios_empleado')
            ->whereIn('empleado_id', $empIds)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->where('tipo', 'normal')
            ->whereNotNull('hora_inicio')
            ->whereNotNull('hora_fin')
            ->get(['empleado_id', 'fecha', 'hora_inicio', 'hora_fin']);

        $toMins = fn(string $t): int => (int) explode(':', $t)[0] * 60 + (int) (explode(':', $t)[1] ?? 0);
        $horasNoct = function (int $i, int $f): float {
            $n = 0;
            $z2s = max($i, 19 * 60); $z2e = min($f, 24 * 60);
            if ($z2e > $z2s) $n += $z2e - $z2s;
            if ($f > 24 * 60) $n += min($f, 30 * 60) - 24 * 60;
            return $n / 60.0;
        };

        // Agrupar por empleado → fecha → turnos
        $grouped = [];
        foreach ($horarios as $h) {
            $grouped[(int) $h->empleado_id][$h->fecha][] = $h;
        }

        $result = [];
        foreach ($grouped as $eid => $dias) {
            $result[$eid] = 0.0;
            foreach ($dias as $turnos) {
                $totalH = 0.0; $totalN = 0.0;
                foreach ($turnos as $t) {
                    $i = $toMins($t->hora_inicio); $f = $toMins($t->hora_fin);
                    if ($f <= $i) $f += 1440;
                    $totalH += ($f - $i) / 60.0;
                    $totalN += $horasNoct($i, $f);
                }
                $maxHoras = $totalN > 4 ? 7 : 8;
                $result[$eid] += max(0.0, $totalH - $maxHoras);
            }
        }

        return array_map(fn($h) => round($h, 2), $result);
    }

    /**
     * Mapa empleado_id → horas nocturnas acumuladas en el período de TRABAJO
     * correspondiente a la quincena de pago dada (Art. 161 CT-SV).
     *
     * Regla de pago vencido:
     *   pago Q1 → trabajo Q2 del mes anterior
     *   pago Q2 → trabajo Q1 del mismo mes
     *
     * Zona nocturna: 19:00–24:00 y 00:00–06:00.
     */
    private function getNocturnidadesMap(array $empIds, int $anioP, int $mesP, int $quincenaP): array
    {
        if (empty($empIds)) return [];

        // Período de trabajo (quincena anterior al pago)
        if ($quincenaP === 1) {
            // Trabajo: Q2 del mes anterior
            $mesTrab   = $mesP === 1 ? 12 : $mesP - 1;
            $anioTrab  = $mesP === 1 ? $anioP - 1 : $anioP;
            $desde     = Carbon::create($anioTrab, $mesTrab, 16);
            $hasta     = Carbon::create($anioTrab, $mesTrab)->endOfMonth()->startOfDay();
        } else {
            // Trabajo: Q1 del mismo mes
            $anioTrab  = $anioP;
            $desde     = Carbon::create($anioTrab, $mesP, 1);
            $hasta     = Carbon::create($anioTrab, $mesP, 15);
        }

        $horarios = DB::connection('pgsql')
            ->table('horarios_empleado')
            ->whereIn('empleado_id', $empIds)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->where('tipo', 'normal')
            ->whereNotNull('hora_inicio')
            ->whereNotNull('hora_fin')
            ->get(['empleado_id', 'hora_inicio', 'hora_fin']);

        $result = [];
        $toMins = fn(string $t): int => (int) explode(':', $t)[0] * 60 + (int) (explode(':', $t)[1] ?? 0);

        foreach ($horarios as $h) {
            $eid    = (int) $h->empleado_id;
            $inicio = $toMins($h->hora_inicio);
            $fin    = $toMins($h->hora_fin);
            if ($fin <= $inicio) $fin += 1440; // cruza medianoche

            $noct = 0;
            // 19:00–24:00
            $z1s = max($inicio, 19 * 60); $z1e = min($fin, 24 * 60);
            if ($z1e > $z1s) $noct += $z1e - $z1s;
            // 00:00–06:00 (solo turnos que cruzan medianoche)
            if ($fin > 1440) $noct += min($fin, 1440 + 6 * 60) - 1440;

            $result[$eid] = ($result[$eid] ?? 0.0) + $noct / 60.0;
        }

        return array_map(fn($h) => round($h, 2), $result);
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

    // ─── Movimientos ─────────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas/{id}/movimientos?empleado_id=X
     * Lista los movimientos snapshotados para una planilla, opcionalmente filtrados por empleado.
     */
    public function movimientos(int $id, Request $request): JsonResponse
    {
        $planilla  = Planilla::findOrFail($id);
        $empleadoId = $request->query('empleado_id');

        $query = PlanillaMovimiento::where('planilla_id', $id)
            ->orderBy('empleado_id')
            ->orderBy('tipo');

        if ($empleadoId) {
            $query->where('empleado_id', (int) $empleadoId);
        }

        $movs      = $query->get();
        $empIds    = $movs->pluck('empleado_id')->unique()->all();
        $empleados = $this->buildEmpleadosMap($empIds);

        $data = $movs->map(function ($m) use ($empleados) {
            $arr = $m->toArray();
            $emp = $empleados[$m->empleado_id] ?? null;
            $arr['empleado_nombre'] = $emp ? trim($emp->nombres . ' ' . $emp->apellidos) : null;
            $arr['empleado_codigo'] = $emp?->codigo;
            return $arr;
        });

        return response()->json(['success' => true, 'data' => $data, 'planilla_estado' => $planilla->estado]);
    }

    /**
     * POST /api/rrhh/planillas/{id}/movimientos
     * Crea un movimiento manual (bonificacion_manual, comision) para un empleado.
     */
    public function crearMovimiento(int $id, Request $request): JsonResponse
    {
        $planilla = Planilla::findOrFail($id);

        if ($planilla->estado !== 'borrador') {
            return response()->json(['success' => false, 'message' => 'Solo se pueden crear movimientos en planillas en borrador.'], 422);
        }

        $data = $request->validate([
            'empleado_id' => 'required|integer',
            'tipo'        => 'required|in:bonificacion_manual,comision',
            'concepto'    => 'required|string|max:255',
            'monto'       => 'required|numeric|min:0.01',
        ]);

        $linea = PlanillaLinea::where('planilla_id', $id)->where('empleado_id', $data['empleado_id'])->first();
        if (!$linea) {
            return response()->json(['success' => false, 'message' => 'El empleado no tiene línea activa en esta planilla.'], 422);
        }

        $mov = PlanillaMovimiento::create([
            'planilla_id'  => $id,
            'empleado_id'  => $data['empleado_id'],
            'tipo'         => $data['tipo'],
            'concepto'     => $data['concepto'],
            'monto'        => round((float) $data['monto'], 2),
            'dias'         => 0,
            'estado'       => 'activo',
        ]);

        $this->ajustarLineaTrasMov($id, $data['empleado_id']);
        $this->svc->recalcularTotalesPlanilla($planilla->fresh());
        $linea = PlanillaLinea::where('planilla_id', $id)->where('empleado_id', $data['empleado_id'])->first();

        return response()->json([
            'success'          => true,
            'message'          => 'Movimiento creado.',
            'data'             => $mov,
            'linea'            => $linea,
            'planilla_totales' => $planilla->fresh()->only(['total_salarios','total_descuentos','total_patronal','total_neto']),
        ]);
    }

    /**
     * PUT /api/rrhh/planillas/{id}/movimientos/{movId}
     * Actualiza monto/dias de un movimiento (solo planillas borrador, no orden_descuento).
     */
    public function updateMovimiento(int $id, int $movId, Request $request): JsonResponse
    {
        $planilla = Planilla::findOrFail($id);

        if ($planilla->estado !== 'borrador') {
            return response()->json(['success' => false, 'message' => 'Solo se pueden editar movimientos en planillas en borrador.'], 422);
        }

        $mov = PlanillaMovimiento::where('planilla_id', $id)->findOrFail($movId);

        if ($mov->tipo === 'orden_descuento') {
            return response()->json(['success' => false, 'message' => 'Las órdenes de descuento no se pueden editar aquí.'], 422);
        }

        if ($mov->estado === 'anulado') {
            return response()->json(['success' => false, 'message' => 'No se puede editar un movimiento anulado.'], 422);
        }

        $data = $request->validate([
            'monto'    => 'nullable|numeric',
            'dias'     => 'nullable|numeric',
            'concepto' => 'nullable|string|max:255',
        ]);

        $mov->update($data);
        $this->ajustarLineaTrasMov($id, $mov->empleado_id);
        $this->svc->recalcularTotalesPlanilla($planilla->fresh());
        $linea = PlanillaLinea::where('planilla_id', $id)->where('empleado_id', $mov->empleado_id)->first();

        return response()->json(['success' => true, 'message' => 'Movimiento actualizado.', 'data' => $mov, 'linea' => $linea, 'planilla_totales' => $planilla->fresh()->only(['total_salarios','total_descuentos','total_patronal','total_neto'])]);
    }

    /**
     * PUT /api/rrhh/planillas/{id}/movimientos/{movId}/anular
     * Marca un movimiento como anulado (solo en planillas borrador).
     */
    public function anularMovimiento(int $id, int $movId): JsonResponse
    {
        $planilla = Planilla::findOrFail($id);

        if ($planilla->estado !== 'borrador') {
            return response()->json(['success' => false, 'message' => 'Solo se pueden anular movimientos en planillas en borrador.'], 422);
        }

        $mov = PlanillaMovimiento::where('planilla_id', $id)->findOrFail($movId);

        if ($mov->estado === 'anulado') {
            return response()->json(['success' => false, 'message' => 'El movimiento ya está anulado.'], 422);
        }

        $mov->update(['estado' => 'anulado']);
        $this->ajustarLineaTrasMov($id, $mov->empleado_id);
        $this->svc->recalcularTotalesPlanilla($planilla->fresh());
        $linea = PlanillaLinea::where('planilla_id', $id)->where('empleado_id', $mov->empleado_id)->first();

        return response()->json(['success' => true, 'message' => 'Movimiento anulado.', 'data' => $mov, 'linea' => $linea, 'planilla_totales' => $planilla->fresh()->only(['total_salarios','total_descuentos','total_patronal','total_neto'])]);
    }

    /**
     * PUT /api/rrhh/planillas/{id}/movimientos/{movId}/reactivar
     * Reactiva un movimiento previamente anulado (solo en planillas borrador).
     */
    public function reactivarMovimiento(int $id, int $movId): JsonResponse
    {
        $planilla = Planilla::findOrFail($id);

        if ($planilla->estado !== 'borrador') {
            return response()->json(['success' => false, 'message' => 'Solo se pueden reactivar movimientos en planillas en borrador.'], 422);
        }

        $mov = PlanillaMovimiento::where('planilla_id', $id)->findOrFail($movId);
        $mov->update(['estado' => 'activo']);
        $this->ajustarLineaTrasMov($id, $mov->empleado_id);
        $this->svc->recalcularTotalesPlanilla($planilla->fresh());
        $linea = PlanillaLinea::where('planilla_id', $id)->where('empleado_id', $mov->empleado_id)->first();

        return response()->json(['success' => true, 'message' => 'Movimiento reactivado.', 'data' => $mov, 'linea' => $linea, 'planilla_totales' => $planilla->fresh()->only(['total_salarios','total_descuentos','total_patronal','total_neto'])]);
    }

    /**
     * Recalcula los campos totales de planilla_lineas desde los movimientos activos.
     * Se llama tras anular o reactivar un movimiento para mantener coherencia.
     */
    private function ajustarLineaTrasMov(int $planillaId, int $eid): void
    {
        $linea = PlanillaLinea::where('planilla_id', $planillaId)->where('empleado_id', $eid)->first();
        if (!$linea) return;

        $movs = PlanillaMovimiento::where('planilla_id', $planillaId)
            ->where('empleado_id', $eid)
            ->where('estado', 'activo')
            ->get();

        $otrosDesc          = 0.0;
        $montoExtras        = 0.0;
        $horasExtras        = 0.0;
        $montoNoct          = 0.0;
        $horasNoct          = 0.0;
        $salAsueto          = 0.0;
        $diasAsueto         = 0.0;
        $montoVacac         = 0.0;
        $bonificacionManual = 0.0;
        $comisiones         = 0.0;

        foreach ($movs as $m) {
            switch ($m->tipo) {
                case 'orden_descuento':
                    $otrosDesc  += abs((float) $m->monto);
                    break;
                case 'horas_extras':
                    $horasExtras += (float) $m->dias;
                    $montoExtras += (float) $m->monto;
                    break;
                case 'nocturnidad':
                    $montoNoct  += (float) $m->monto;
                    $horasNoct  += (float) $m->dias;
                    break;
                case 'asueto':
                    if ((float) $m->dias > 0)  $diasAsueto += (float) $m->dias;
                    if ((float) $m->monto > 0) $salAsueto  += (float) $m->monto;
                    break;
                case 'pago_vacaciones':
                    $montoVacac += (float) $m->monto;
                    break;
                case 'bonificacion_manual':
                    $bonificacionManual += (float) $m->monto;
                    break;
                case 'comision':
                    $comisiones += (float) $m->monto;
                    break;
            }
        }

        // bonificaciones = sistema (re-leído de la tabla fuente) + manuales desde movimientos
        $planillaObj = Planilla::findOrFail($planillaId);
        $fechaIn     = Carbon::parse($planillaObj->fecha_inicio);
        $fechaFin2   = Carbon::parse($planillaObj->fecha_fin);
        $bonifMapEmp = $this->svc->getBonificacionesMap([$eid], $fechaIn, $fechaFin2);
        $systemBonif = array_sum(array_column($bonifMapEmp[$eid] ?? [], 'monto'));
        $totalBonif  = round($systemBonif + $bonificacionManual, 2);

        $totalDesc = round(
            (float) $linea->afp_empleado + (float) $linea->isss_empleado +
            (float) $linea->renta + $otrosDesc, 2
        );
        $salNeto = round(
            (float) $linea->salario_proporcional + $salAsueto + $montoExtras +
            $montoNoct + $totalBonif + $comisiones + $montoVacac - $totalDesc, 2
        );

        $update = [
            'otros_descuentos'          => round($otrosDesc,          2),
            'total_descuentos_empleado' => $totalDesc,
            'salario_neto'              => $salNeto,
            'monto_horas_extras'        => round($montoExtras,        2),
            'horas_extras'              => round($horasExtras,        2),
            'monto_nocturnidades'       => round($montoNoct,          2),
            'dias_asueto'               => round($diasAsueto,         2),
            'salario_asueto'            => round($salAsueto,          2),
            'vacaciones'                => round($montoVacac,         2),
            'comisiones'                => round($comisiones,         2),
            'bonificaciones'            => $totalBonif,
        ];
        // Solo sobreescribir horas_nocturnas si los movimientos traen dias > 0
        if ($horasNoct > 0) {
            $update['horas_nocturnas'] = round($horasNoct, 2);
        }

        $linea->update($update);
    }

    // ─── Add / Remove empleado ────────────────────────────────────────────────

    /**
     * POST /api/rrhh/planillas/{id}/empleados
     * Agrega un empleado a una planilla en borrador y calcula su línea.
     */
    public function addEmpleado(int $id, Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($id, $request) {
            $validated = $request->validate(['empleado_id' => 'required|integer']);
            $eid = (int) $validated['empleado_id'];

            $planilla = Planilla::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'Solo se pueden agregar empleados a planillas en borrador.'], 422);
            }

            // Verificar si está explícitamente excluido
            $estaExcluido = DB::connection('rrhh')
                ->table('planilla_exclusiones')
                ->where('planilla_id', $id)
                ->where('empleado_id', $eid)
                ->exists();
            if ($estaExcluido) {
                return response()->json(['success' => false, 'message' => 'Este empleado fue excluido manualmente de esta planilla. Use "Restaurar" para volver a incluirlo.'], 422);
            }

            if (PlanillaLinea::where('planilla_id', $id)->where('empleado_id', $eid)->where('estado', 'activo')->exists()) {
                return response()->json(['success' => false, 'message' => 'El empleado ya está en esta planilla.'], 422);
            }

            $empRow = DB::connection('pgsql')
                ->table('empleados as e')
                ->leftJoin('sucursales as s', 's.id', '=', 'e.sucursal_id')
                ->where('e.id', $eid)
                ->select('e.id', 'e.salario_base', 's.geo_departamento_id', 'e.es_pensionado', 'e.fecha_ingreso')
                ->first();

            if (!$empRow) {
                return response()->json(['success' => false, 'message' => 'Empleado no encontrado.'], 404);
            }

            [$fechaInicio, $fechaFin] = $this->calc->rangoQuincena($planilla->anio, $planilla->mes, $planilla->quincena);

            $emp = (array) $empRow;

            // Empleados con salario 0 no van en planilla de salarios
            if ($planilla->tipo_planilla === 'salarios' && (float) ($emp['salario_base'] ?? 0) === 0.0) {
                return response()->json(['success' => false, 'message' => 'Este empleado tiene salario base $0 y no puede incluirse en una planilla de salarios.'], 422);
            }

            $ctx      = $this->svc->cargarContexto([$eid], $planilla->anio, $planilla->mes, $planilla->quincena, $fechaInicio, $fechaFin);
            $resultado = $this->svc->calcularLinea($emp, $ctx, $fechaInicio, $fechaFin);

            DB::connection('rrhh')->beginTransaction();
            try {
                $linea = PlanillaLinea::create(array_merge(['planilla_id' => $id, 'empleado_id' => $eid], $resultado));

                $this->svc->recalcularTotalesPlanilla($planilla);

                $this->svc->snapshotMovimientosEmpleado(
                    $id, $eid,
                    (float) ($emp['salario_base'] ?? 0),
                    $emp['fecha_ingreso'] ?? null,
                    $ctx, $fechaInicio, $fechaFin
                );

                DB::connection('rrhh')->commit();
            } catch (\Throwable $e) {
                DB::connection('rrhh')->rollBack();
                throw $e;
            }

            return response()->json(['success' => true, 'message' => 'Empleado agregado a la planilla.', 'data' => $linea]);
        });
    }

    /**
     * DELETE /api/rrhh/planillas/{id}/empleados/{empleadoId}
     * Excluye un empleado de una planilla en borrador (soft-delete + registro en planilla_exclusiones).
     */
    public function removeEmpleado(int $id, int $empleadoId): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id, $empleadoId) {
            $planilla = Planilla::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'Solo se pueden excluir empleados de planillas en borrador.'], 422);
            }

            $linea = PlanillaLinea::where('planilla_id', $id)
                ->where('empleado_id', $empleadoId)
                ->where('estado', 'activo')
                ->first();

            if (!$linea) {
                return response()->json(['success' => false, 'message' => 'El empleado no está en esta planilla.'], 404);
            }

            DB::connection('rrhh')->beginTransaction();
            try {
                // Soft-delete: marcar como excluido
                $linea->update(['estado' => 'excluido']);

                // Registrar en blocklist para que persista en regeneraciones
                DB::connection('rrhh')->table('planilla_exclusiones')->updateOrInsert(
                    ['planilla_id' => $id, 'empleado_id' => $empleadoId],
                    ['excluido_por' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]
                );

                $this->svc->recalcularTotalesPlanilla($planilla);

                DB::connection('rrhh')->commit();
            } catch (\Throwable $e) {
                DB::connection('rrhh')->rollBack();
                throw $e;
            }

            return response()->json(['success' => true, 'message' => 'Empleado excluido de la planilla.']);
        });
    }

    /**
     * PATCH /api/rrhh/planillas/{id}/empleados/{empleadoId}/restaurar
     * Restaura un empleado excluido a la planilla (recalcula su línea).
     */
    public function restaurarEmpleado(int $id, int $empleadoId): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id, $empleadoId) {
            $planilla = Planilla::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'Solo se pueden restaurar empleados en planillas en borrador.'], 422);
            }

            $linea = PlanillaLinea::where('planilla_id', $id)
                ->where('empleado_id', $empleadoId)
                ->where('estado', 'excluido')
                ->first();

            if (!$linea) {
                return response()->json(['success' => false, 'message' => 'El empleado no está excluido de esta planilla.'], 404);
            }

            [$fechaInicio, $fechaFin] = $this->calc->rangoQuincena($planilla->anio, $planilla->mes, $planilla->quincena);

            $empRow = DB::connection('pgsql')
                ->table('empleados as e')
                ->leftJoin('sucursales as s', 's.id', '=', 'e.sucursal_id')
                ->where('e.id', $empleadoId)
                ->select('e.id', 'e.salario_base', 's.geo_departamento_id', 'e.es_pensionado', 'e.fecha_ingreso')
                ->first();

            DB::connection('rrhh')->beginTransaction();
            try {
                // Eliminar del blocklist
                DB::connection('rrhh')->table('planilla_exclusiones')
                    ->where('planilla_id', $id)
                    ->where('empleado_id', $empleadoId)
                    ->delete();

                if ($empRow) {
                    // Recalcular línea con valores actuales
                    $emp = (array) $empRow;
                    $ctx = $this->svc->cargarContexto([$empleadoId], $planilla->anio, $planilla->mes, $planilla->quincena, $fechaInicio, $fechaFin);
                    $resultado = $this->svc->calcularLinea($emp, $ctx, $fechaInicio, $fechaFin);

                    $linea->update(array_merge($resultado, ['estado' => 'activo']));

                    // Regenerar movimientos del empleado
                    DB::connection('rrhh')->table('planilla_movimientos')
                        ->where('planilla_id', $id)
                        ->where('empleado_id', $empleadoId)
                        ->where('estado', 'activo')
                        ->delete();

                    $this->svc->snapshotMovimientosEmpleado(
                        $id, $empleadoId,
                        (float) ($emp['salario_base'] ?? 0),
                        $emp['fecha_ingreso'] ?? null,
                        $ctx, $fechaInicio, $fechaFin
                    );
                } else {
                    $linea->update(['estado' => 'activo']);
                }

                $this->svc->recalcularTotalesPlanilla($planilla);

                DB::connection('rrhh')->commit();
            } catch (\Throwable $e) {
                DB::connection('rrhh')->rollBack();
                throw $e;
            }

            return response()->json(['success' => true, 'message' => 'Empleado restaurado a la planilla.', 'data' => $linea->fresh()]);
        });
    }

    // ─── Recalcular ───────────────────────────────────────────────────────────

    /**
     * POST /api/rrhh/planillas/{id}/recalcular
     * Recalcula todas las líneas con los eventos actuales (mismo set de empleados).
     */
    public function recalcular(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $planilla = Planilla::with('lineas')->findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'Solo se pueden recalcular planillas en borrador.'], 422);
            }

            [$fechaInicio, $fechaFin] = $this->calc->rangoQuincena($planilla->anio, $planilla->mes, $planilla->quincena);
            $diasQuincena = PlanillaCalculatorService::DIAS_QUINCENA;

            // Tomar los empleados activos actuales (excluidos no se recalculan).
            // Si la planilla no tiene líneas activas (caso de recuperación), regenerar desde todos los activos.
            $empIdsBruto = PlanillaLinea::where('planilla_id', $id)
                ->where('estado', 'activo')
                ->pluck('empleado_id')->unique()->toArray();

            // IDs excluidos explícitamente: se preservan tal cual
            $exclusionesIds = DB::connection('rrhh')
                ->table('planilla_exclusiones')
                ->where('planilla_id', $id)
                ->pluck('empleado_id')
                ->toArray();

            $tipoPlanilla    = $planilla->tipo_planilla ?? 'salarios';
            $fechaInicioStr  = $fechaInicio->toDateString();
            $fechaFinStr     = $fechaFin->toDateString();

            if (empty($empIdsBruto)) {
                // Sin líneas: regenerar desde todos los empleados activos del tipo.
                // No filtrar por fecha_inicio del tipo-planilla para cubrir el caso donde
                // los registros de empleado_tipo_planilla fueron creados/migrados después del período.
                $excluirPruebaQ1 = DB::connection('rrhh')
                    ->table('periodos_prueba')
                    ->where('estado', 'en_prueba')
                    ->where('fecha_fin_estimada', '>=', $fechaFinStr)
                    ->pluck('empleado_id')->toArray();

                $empIds = DB::connection('pgsql')
                    ->table('empleados as e')
                    ->join('empleado_tipo_planilla as etp', 'etp.empleado_id', '=', 'e.id')
                    ->join('tipo_planilla as tp', function ($join) use ($tipoPlanilla) {
                        $join->on('tp.id', '=', 'etp.tipo_planilla_id')
                             ->where('tp.slug', '=', $tipoPlanilla);
                    })
                    ->where('e.activo', true)
                    ->when(!empty($excluirPruebaQ1), fn($q) => $q->whereNotIn('e.id', $excluirPruebaQ1))
                    ->pluck('e.id')->unique()->toArray();
            } elseif ($tipoPlanilla === 'honorarios') {
                // Honorarios: solo tipo asignado explícitamente
                $honorariosIds = DB::table('empleado_tipo_planilla as etp')
                    ->join('tipo_planilla as tp', 'tp.id', '=', 'etp.tipo_planilla_id')
                    ->where('tp.slug', 'honorarios')
                    ->where('etp.fecha_inicio', '<=', $fechaFinStr)
                    ->where(function ($q) use ($fechaInicioStr) {
                        $q->whereNull('etp.fecha_fin')->orWhere('etp.fecha_fin', '>=', $fechaInicioStr);
                    })
                    ->pluck('etp.empleado_id')->toArray();

                $empIds = DB::connection('pgsql')
                    ->table('empleados')
                    ->whereIn('id', $empIdsBruto)
                    ->whereIn('id', $honorariosIds)
                    ->pluck('id')->toArray();
            } else {
                // Resto de tipos: solo los que tienen el tipo asignado Y no están en prueba activa
                // (empleados que salen de prueba a mitad de quincena sí se mantienen)
                // fecha_inicio: NULL se trata como "desde siempre" para tolerar datos con backfill incompleto
                $conTipo = DB::connection('pgsql')
                    ->table('empleado_tipo_planilla as etp')
                    ->join('tipo_planilla as tp', function ($join) use ($tipoPlanilla) {
                        $join->on('tp.id', '=', 'etp.tipo_planilla_id')->where('tp.slug', '=', $tipoPlanilla);
                    })
                    ->where(function ($q) use ($fechaFinStr) {
                        $q->whereNull('etp.fecha_inicio')->orWhere('etp.fecha_inicio', '<=', $fechaFinStr);
                    })
                    ->where(function ($q) use ($fechaInicioStr) {
                        $q->whereNull('etp.fecha_fin')->orWhere('etp.fecha_fin', '>=', $fechaInicioStr);
                    })
                    ->pluck('etp.empleado_id')->toArray();

                // Excluir solo empleados cuya prueba no termina antes del fin del período
                $excluirPruebaIds = DB::connection('rrhh')
                    ->table('periodos_prueba')
                    ->where('estado', 'en_prueba')
                    ->where('fecha_fin_estimada', '>=', $fechaFinStr)
                    ->pluck('empleado_id')
                    ->toArray();

                // Empleados existentes en la planilla que siguen siendo válidos
                $empIds = DB::connection('pgsql')
                    ->table('empleados')
                    ->whereIn('id', $empIdsBruto)
                    ->whereIn('id', $conTipo)
                    ->when(!empty($excluirPruebaIds), fn($q) => $q->whereNotIn('id', $excluirPruebaIds))
                    ->pluck('id')->toArray();

                // Desvinculados DESPUÉS del período: trabajaron todo el período pero aún no están en planilla_lineas.
                // Se buscan entre los que tienen el tipo correcto y no están en exclusiones.
                $desvinculadosDespuesIds = DB::connection('rrhh')
                    ->table('desvinculaciones')
                    ->where('estado', 'aprobado')
                    ->where('fecha_efectiva', '>', $fechaFinStr)
                    ->pluck('empleado_id')
                    ->unique()
                    ->all();

                if (!empty($desvinculadosDespuesIds)) {
                    $nuevosDesvinc = DB::connection('pgsql')
                        ->table('empleado_tipo_planilla as etp')
                        ->join('tipo_planilla as tp', function ($join) use ($tipoPlanilla) {
                            $join->on('tp.id', '=', 'etp.tipo_planilla_id')->where('tp.slug', '=', $tipoPlanilla);
                        })
                        ->whereIn('etp.empleado_id', $desvinculadosDespuesIds)
                        ->where(function ($q) use ($fechaFinStr) {
                            $q->whereNull('etp.fecha_inicio')->orWhere('etp.fecha_inicio', '<=', $fechaFinStr);
                        })
                        ->where(function ($q) use ($fechaInicioStr) {
                            $q->whereNull('etp.fecha_fin')->orWhere('etp.fecha_fin', '>=', $fechaInicioStr);
                        })
                        ->whereNotIn('etp.empleado_id', $exclusionesIds)
                        ->whereNotIn('etp.empleado_id', $empIds)
                        ->pluck('etp.empleado_id')
                        ->unique()
                        ->toArray();

                    $empIds = array_unique(array_merge($empIds, $nuevosDesvinc));
                }

                // Desvinculados DENTRO del período: proporcional (pueden no estar en planilla_lineas).
                // Empleados que se fueron antes de que se generara/recalculara la planilla.
                $desvinculadosDentroIds = DB::connection('rrhh')
                    ->table('desvinculaciones')
                    ->where('estado', 'aprobado')
                    ->where('fecha_efectiva', '>=', $fechaInicioStr)
                    ->where('fecha_efectiva', '<=', $fechaFinStr)
                    ->pluck('empleado_id')
                    ->unique()
                    ->all();

                if (!empty($desvinculadosDentroIds)) {
                    $nuevosDesvDentro = DB::connection('pgsql')
                        ->table('empleado_tipo_planilla as etp')
                        ->join('tipo_planilla as tp', function ($join) use ($tipoPlanilla) {
                            $join->on('tp.id', '=', 'etp.tipo_planilla_id')->where('tp.slug', '=', $tipoPlanilla);
                        })
                        ->whereIn('etp.empleado_id', $desvinculadosDentroIds)
                        ->where(function ($q) use ($fechaFinStr) {
                            $q->whereNull('etp.fecha_inicio')->orWhere('etp.fecha_inicio', '<=', $fechaFinStr);
                        })
                        ->where(function ($q) use ($fechaInicioStr) {
                            $q->whereNull('etp.fecha_fin')->orWhere('etp.fecha_fin', '>=', $fechaInicioStr);
                        })
                        ->whereNotIn('etp.empleado_id', $exclusionesIds)
                        ->whereNotIn('etp.empleado_id', $empIds)
                        ->pluck('etp.empleado_id')
                        ->unique()
                        ->toArray();

                    $empIds = array_unique(array_merge($empIds, $nuevosDesvDentro));
                }

                // Empleados activos con tipo correcto que no estén en la planilla aún
                // (adquirieron el tipo después de que se generó la planilla original).
                $activosFaltantes = DB::connection('pgsql')
                    ->table('empleados as e')
                    ->join('empleado_tipo_planilla as etp', 'etp.empleado_id', '=', 'e.id')
                    ->join('tipo_planilla as tp', function ($join) use ($tipoPlanilla) {
                        $join->on('tp.id', '=', 'etp.tipo_planilla_id')->where('tp.slug', '=', $tipoPlanilla);
                    })
                    ->where('e.activo', true)
                    ->where('e.salario_base', '>', 0)
                    ->where(function ($q) use ($fechaFinStr) {
                        $q->whereNull('etp.fecha_inicio')->orWhere('etp.fecha_inicio', '<=', $fechaFinStr);
                    })
                    ->where(function ($q) use ($fechaInicioStr) {
                        $q->whereNull('etp.fecha_fin')->orWhere('etp.fecha_fin', '>=', $fechaInicioStr);
                    })
                    ->whereNotIn('e.id', $exclusionesIds)
                    ->whereNotIn('e.id', $empIds)
                    ->pluck('e.id')
                    ->unique()
                    ->toArray();

                $empIds = array_unique(array_merge($empIds, $activosFaltantes));
            }

            // Eliminar líneas ACTIVAS de empleados que ya no corresponden a este tipo de planilla
            $removidos = array_diff($empIdsBruto, $empIds);
            if (!empty($removidos)) {
                PlanillaLinea::where('planilla_id', $id)
                    ->whereIn('empleado_id', $removidos)
                    ->where('estado', 'activo')
                    ->delete();
            }

            if (empty($empIds)) {
                Log::warning('recalcular: sin empIds válidos', [
                    'planilla_id'    => $id,
                    'tipo_planilla'  => $tipoPlanilla,
                    'quincena'       => $planilla->quincena,
                    'mes'            => $planilla->mes,
                    'anio'           => $planilla->anio,
                    'empIdsBruto'    => count($empIdsBruto),
                    'conTipo_count'  => count($conTipo ?? []),
                    'fechaInicioStr' => $fechaInicioStr,
                    'fechaFinStr'    => $fechaFinStr,
                ]);
                return response()->json(['success' => false, 'message' => 'La planilla no tiene empleados válidos para este tipo.'], 422);
            }

            // Empleados que salen de prueba a mitad de quincena (necesitan inicio proporcional)
            $transicionandoRecalc = ($tipoPlanilla !== 'honorarios')
                ? DB::connection('rrhh')
                    ->table('periodos_prueba')
                    ->where('estado', 'en_prueba')
                    ->where('fecha_fin_estimada', '>=', $fechaInicioStr)
                    ->where('fecha_fin_estimada', '<', $fechaFinStr)
                    ->whereIn('empleado_id', $empIds)
                    ->pluck('fecha_fin_estimada', 'empleado_id')
                    ->map(fn($f) => Carbon::parse($f)->addDay()->toDateString())
                : collect();

            $empleadosRows = DB::connection('pgsql')
                ->table('empleados as e')
                ->leftJoin('sucursales as s', 's.id', '=', 'e.sucursal_id')
                ->whereIn('e.id', $empIds)
                ->select('e.id', 'e.salario_base', 's.geo_departamento_id', 'e.es_pensionado', 'e.fecha_ingreso')
                ->get()
                ->map(function ($r) use ($transicionandoRecalc) {
                    $r = (array) $r;
                    if ($transicionandoRecalc->has((int) $r['id'])) {
                        $r['fecha_ingreso_efectiva'] = $transicionandoRecalc[(int) $r['id']];
                    }
                    return $r;
                })
                ->all();

            $ctx = $this->svc->cargarContexto($empIds, $planilla->anio, $planilla->mes, $planilla->quincena, $fechaInicio, $fechaFin);

            // Mapa de desvinculados (por si alguno fue desvinculado después de generar)
            $desvinculados = DB::connection('rrhh')
                ->table('desvinculaciones')
                ->where('estado', 'aprobado')
                ->where('fecha_efectiva', '>=', $fechaInicio->toDateString())
                ->where('fecha_efectiva', '<=', $fechaFin->toDateString())
                ->whereIn('empleado_id', $empIds)
                ->select('empleado_id', 'fecha_efectiva')
                ->get()
                ->keyBy('empleado_id');

            // Preservar overrides manuales de líneas activas antes de borrarlas
            $overridesMap = PlanillaLinea::where('planilla_id', $id)
                ->where('estado', 'activo')
                ->where(fn($q) => $q->whereNotNull('horas_extras_override')->orWhereNotNull('horas_nocturnas_override'))
                ->get(['empleado_id', 'horas_extras_override', 'horas_nocturnas_override'])
                ->keyBy('empleado_id')
                ->map(fn($l) => [
                    'ext'  => $l->horas_extras_override !== null ? (float) $l->horas_extras_override : null,
                    'noct' => $l->horas_nocturnas_override !== null ? (float) $l->horas_nocturnas_override : null,
                ])
                ->all();

            $lineasCalc = [];

            DB::connection('rrhh')->beginTransaction();
            try {
                PlanillaLinea::where('planilla_id', $id)->where('estado', 'activo')->delete();

                foreach ($empleadosRows as $emp) {
                    $eid = (int) $emp['id'];

                    $desvinc = $desvinculados->get($eid);
                    $hastaEfectivo = $desvinc
                        ? (Carbon::parse($desvinc->fecha_efectiva)->lessThan($fechaFin)
                            ? Carbon::parse($desvinc->fecha_efectiva)
                            : $fechaFin)
                        : null;

                    $extOverride  = $overridesMap[$eid]['ext']  ?? null;
                    $noctOverride = $overridesMap[$eid]['noct'] ?? null;

                    $resultado = $this->svc->calcularLinea($emp, $ctx, $fechaInicio, $fechaFin, $hastaEfectivo, $extOverride, $noctOverride);

                    PlanillaLinea::create(array_merge(['planilla_id' => $id, 'empleado_id' => $eid], $resultado));
                    $lineasCalc[] = $resultado;
                }

                $totales = $this->calc->calcularTotalesPlanilla($lineasCalc);
                $planilla->update($totales);

                $this->svc->snapshotMovimientos($id, $empleadosRows, $ctx, $fechaInicio, $fechaFin, $exclusionesIds);

                DB::connection('rrhh')->commit();
            } catch (\Throwable $e) {
                DB::connection('rrhh')->rollBack();
                throw $e;
            }

            return $this->show($id);
        });
    }

    // ─── Pivot Bancos ─────────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas/{id}/bancos
     * Retorna las ODD aplicadas en esta planilla (desde planilla_movimientos),
     * agrupadas por empleado y acreedor, listas para el pivot del tab Bancos.
     */
    public function bancos(int $id): JsonResponse
    {
        $planilla = Planilla::with('lineas')->findOrFail($id);

        $movs = DB::connection('rrhh')
            ->table('planilla_movimientos as pm')
            ->where('pm.planilla_id', $id)
            ->where('pm.tipo', 'orden_descuento')
            ->where('pm.estado', 'activo')
            ->select('pm.empleado_id', 'pm.referencia_id as acreedor_id', 'pm.concepto', 'pm.monto')
            ->get();

        // Obtener nombres de acreedores en bloque
        $acreedorIds = $movs->pluck('acreedor_id')->filter()->unique()->values()->all();
        $acreedores  = [];
        if (!empty($acreedorIds)) {
            $acreedores = DB::connection('pgsql')
                ->table('acreedores')
                ->whereIn('id', $acreedorIds)
                ->pluck('nombre', 'id')
                ->all();
        }

        // Para acreedores sin id en referencia_id, extraer nombre del concepto (formato "Acreedor — Ref")
        $rows = $movs->map(function ($m) use ($acreedores) {
            $acreedorId   = $m->acreedor_id ? (int) $m->acreedor_id : null;
            $acreedorNombre = $acreedorId
                ? ($acreedores[$acreedorId] ?? $m->concepto)
                : (str_contains($m->concepto, ' — ') ? explode(' — ', $m->concepto)[0] : $m->concepto);

            return [
                'empleado_id'     => (int) $m->empleado_id,
                'acreedor_id'     => $acreedorId,
                'acreedor_nombre' => $acreedorNombre,
                'monto'           => abs((float) $m->monto),
            ];
        });

        return response()->json($rows->values());
    }

    // ─── Cerrar planilla ──────────────────────────────────────────────────────

    /**
     * PUT /api/rrhh/planillas/{id}/cerrar
     * Cambia estado a 'cerrada'. Solo desde 'aprobada'.
     */
    public function cerrar(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $planilla = Planilla::findOrFail($id);

            if ($planilla->estado !== 'aprobada') {
                return response()->json(['success' => false, 'message' => 'Solo se pueden cerrar planillas aprobadas.'], 422);
            }

            $planilla->update(['estado' => 'cerrada']);

            // Histórico de pagos de vacaciones
            $movsPagVac = DB::connection('rrhh')
                ->table('planilla_movimientos')
                ->where('planilla_id', $id)
                ->where('tipo', 'pago_vacaciones')
                ->where('estado', 'activo')
                ->get();

            foreach ($movsPagVac as $mov) {
                $linea = PlanillaLinea::where('planilla_id', $id)
                    ->where('empleado_id', $mov->empleado_id)
                    ->first();
                DB::connection('rrhh')->table('historico_pago_vacaciones')->updateOrInsert(
                    ['empleado_id' => $mov->empleado_id, 'fecha_aniversario' => $mov->fecha_inicio],
                    [
                        'planilla_id'  => $id,
                        'salario_base' => $linea?->salario_base ?? 0,
                        'monto'        => $mov->monto,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]
                );
            }

            return response()->json(['success' => true, 'message' => 'Planilla cerrada.', 'data' => $planilla->fresh()]);
        });
    }

    // ─── Snapshot helpers ─────────────────────────────────────────────────────

    /**
     * Genera/reemplaza el snapshot de movimientos para todos los empleados de la planilla.
     */
    private function snapshotMovimientos(
        int $planillaId,
        array $empleados,
        $permisos, $incapacidades, $vacaciones, $ausencias, $suspensiones,
        $asuetos, array $horariosAsueto, array $horasExtrasMap, array $nocturnidadesMap,
        Carbon $fechaInicio, Carbon $fechaFin, int $diasQuincena, array $ordenesMap = []
    ): void {
        // Borrar solo los movimientos 'activo' de toda la planilla.
        // Los 'anulado' se conservan; snapshotMovimientosEmpleado los respeta al reconstruir.
        DB::connection('rrhh')
            ->table('planilla_movimientos')
            ->where('planilla_id', $planillaId)
            ->where('estado', 'activo')
            ->delete();

        foreach ($empleados as $emp) {
            $eid          = (int) $emp['id'];
            $salBase      = (float) ($emp['salario_base'] ?? 0);
            $fechaIngreso = $emp['fecha_ingreso'] ?? null;
            $this->snapshotMovimientosEmpleado(
                $planillaId, $eid,
                $permisos, $incapacidades, $vacaciones, $ausencias, $suspensiones,
                $asuetos, $horariosAsueto, $horasExtrasMap, $nocturnidadesMap,
                $fechaInicio, $fechaFin, $diasQuincena, $salBase, $ordenesMap, $fechaIngreso
            );
        }
    }

    /**
     * Genera el snapshot de movimientos para un único empleado.
     */
    private function snapshotMovimientosEmpleado(
        int $planillaId,
        int $eid,
        $permisos, $incapacidades, $vacaciones, $ausencias, $suspensiones,
        $asuetos, array $horariosAsueto, array $horasExtrasMap, array $nocturnidadesMap,
        Carbon $fechaInicio, Carbon $fechaFin, int $diasQuincena, float $salBase,
        array $ordenesMap = [], ?string $fechaIngreso = null
    ): void {
        // Cargar fingerprints de movimientos anulados para este empleado en esta planilla.
        // Un movimiento anulado NO se recrea ni se toca durante el recálculo.
        $anuladosSet = DB::connection('rrhh')
            ->table('planilla_movimientos')
            ->where('planilla_id', $planillaId)
            ->where('empleado_id', $eid)
            ->where('estado', 'anulado')
            ->get(['tipo', 'referencia_id', 'concepto'])
            ->mapWithKeys(fn($r) => [$r->tipo . '|' . ($r->referencia_id ?? $r->concepto) => true])
            ->all();

        // Eliminar solo los movimientos 'activo' de este empleado; los anulados se conservan intactos.
        DB::connection('rrhh')
            ->table('planilla_movimientos')
            ->where('planilla_id', $planillaId)
            ->where('empleado_id', $eid)
            ->where('estado', 'activo')
            ->delete();

        $rows = [];

        // Permisos
        foreach ($permisos->where('empleado_id', $eid) as $p) {
            $categoria = $p->tipoPermiso?->categoria ?? 'con_goce';
            $tipo      = $categoria === 'sin_goce' ? 'permiso_sin_goce' : 'permiso_con_goce';
            $dias      = (float) ($p->dias ?? ($p->horas_solicitadas ? $p->horas_solicitadas / 8 : 0));
            if ($dias <= 0) continue;
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => $tipo,
                'concepto'     => $p->tipoPermiso?->nombre ?? 'Permiso',
                'fecha_inicio' => $p->fecha,
                'fecha_fin'    => $p->fecha,
                'dias'         => $categoria === 'sin_goce' ? -$dias : 0,
                'monto'        => null,
                'referencia_id'=> $p->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Incapacidades
        foreach ($incapacidades->where('empleado_id', $eid) as $inc) {
            $diasEnQ = $this->diasSolapados($inc->fecha_inicio, $inc->fecha_fin, $fechaInicio, $fechaFin);
            if ($diasEnQ <= 0) continue;
            $diasDesc = $inc->tipo_institucion === 'privada'
                ? $diasEnQ
                : max(0, min((int) $inc->dias - 3, $diasEnQ));
            $tipo = $inc->tipo_institucion === 'isss' ? 'incapacidad_isss' : 'incapacidad_privada';
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => $tipo,
                'concepto'     => 'Incapacidad ' . strtoupper($inc->tipo_institucion ?? ''),
                'fecha_inicio' => $inc->fecha_inicio,
                'fecha_fin'    => $inc->fecha_fin,
                'dias'         => -$diasDesc,
                'monto'        => null,
                'referencia_id'=> $inc->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Vacaciones
        foreach ($vacaciones->where('empleado_id', $eid) as $v) {
            $diasEnQ = $this->diasSolapados($v->fecha_inicio, $v->fecha_fin, $fechaInicio, $fechaFin);
            if ($diasEnQ <= 0) continue;
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => 'vacacion',
                'concepto'     => 'Vacaciones',
                'fecha_inicio' => $v->fecha_inicio,
                'fecha_fin'    => $v->fecha_fin,
                'dias'         => -$diasEnQ,
                'monto'        => null,
                'referencia_id'=> $v->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Ausencias injustificadas
        foreach ($ausencias->where('empleado_id', $eid) as $a) {
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => 'ausencia',
                'concepto'     => 'Ausencia injustificada',
                'fecha_inicio' => $a->fecha,
                'fecha_fin'    => $a->fecha,
                'dias'         => -1,
                'monto'        => null,
                'referencia_id'=> $a->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Suspensiones disciplinarias
        foreach ($suspensiones->filter(fn($s) => $s->amonestacion?->empleado_id === $eid) as $s) {
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => 'suspension',
                'concepto'     => 'Suspensión disciplinaria',
                'fecha_inicio' => $s->fecha,
                'fecha_fin'    => $s->fecha,
                'dias'         => -1,
                'monto'        => null,
                'referencia_id'=> $s->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Asuetos (positivos)
        $geoDptoEmp = null; // No disponible aquí; se omite filtro departamental para el snapshot
        foreach ($asuetos as $asueto) {
            $fechaStr = $asueto->fecha instanceof Carbon
                ? $asueto->fecha->toDateString()
                : substr((string) $asueto->fecha, 0, 10);
            $fechasNormal = $horariosAsueto[$eid] ?? [];
            if (!in_array($fechaStr, $fechasNormal)) continue;
            $montoAsueto = round(($salBase / 30), 2);
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => 'asueto',
                'concepto'     => 'Asueto trabajado',
                'fecha_inicio' => $fechaStr,
                'fecha_fin'    => $fechaStr,
                'dias'         => 1,
                'monto'        => $montoAsueto,
                'referencia_id'=> $asueto->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Horas extras (positivas)
        $horasExt = (float) ($horasExtrasMap[$eid] ?? 0);
        if ($horasExt > 0) {
            $montoExt = round(($salBase / 240) * 2 * $horasExt, 2);
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => 'horas_extras',
                'concepto'     => "Horas extras aprobadas ({$horasExt}h)",
                'fecha_inicio' => null,
                'fecha_fin'    => null,
                'dias'         => 0,
                'monto'        => $montoExt,
                'referencia_id'=> null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Nocturnidades (25% sobre tarifa hora base, Art. 161 CT-SV)
        $horasNoct = (float) ($nocturnidadesMap[$eid] ?? 0);
        if ($horasNoct > 0) {
            $montoNoct = round(($salBase / 240) * 0.25 * $horasNoct, 2);
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => 'nocturnidad',
                'concepto'     => "Recargo nocturno ({$horasNoct}h)",
                'fecha_inicio' => null,
                'fecha_fin'    => null,
                'dias'         => 0,
                'monto'        => $montoNoct,
                'referencia_id'=> null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Órdenes de descuento activas (egresos fijos)
        foreach ($ordenesMap[$eid] ?? [] as $ord) {
            $monto = (float) $ord['monto_quincenal'];
            if ($monto <= 0) continue;
            $acreedor  = $ord['acreedor_nombre'] ?? null;
            $concepto  = $ord['concepto'] ?? 'Orden de descuento';
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => 'orden_descuento',
                'concepto'     => $acreedor ? "{$acreedor} — {$concepto}" : $concepto,
                'fecha_inicio' => null,
                'fecha_fin'    => null,
                'dias'         => 0,
                'monto'        => -$monto,
                'referencia_id'=> $ord['acreedor_id'] ?? null,  // acreedor_id para pivot Bancos
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Pago de vacaciones (ingreso positivo al cumplir aniversario, Art. 177 CT-SV)
        if ($fechaIngreso) {
            $ingreso = Carbon::parse($fechaIngreso);
            if ($ingreso->lt($fechaInicio)) {
                $anioQ = $fechaInicio->year;
                try {
                    $aniversario = Carbon::create($anioQ, $ingreso->month, $ingreso->day);
                } catch (\Exception $e) {
                    $aniversario = Carbon::create($anioQ, $ingreso->month, 28);
                }
                if ($aniversario && $aniversario->between($fechaInicio, $fechaFin)) {
                    $montoVacaciones = round(($salBase / 2) * 0.30, 2); // 30% sobre quincena (Art. 177 CT-SV)
                    $rows[] = [
                        'planilla_id'   => $planillaId,
                        'empleado_id'   => $eid,
                        'tipo'          => 'pago_vacaciones',
                        'concepto'      => "Pago vacaciones ({$anioQ})",
                        'fecha_inicio'  => $aniversario->toDateString(),
                        'fecha_fin'     => $aniversario->toDateString(),
                        'dias'          => 0,
                        'monto'         => $montoVacaciones,
                        'referencia_id' => null,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];
                }
            }
        }

        // Filtrar filas que ya tienen un movimiento anulado con el mismo fingerprint.
        $rows = array_values(array_filter($rows, function ($row) use ($anuladosSet) {
            $key = $row['tipo'] . '|' . ($row['referencia_id'] ?? $row['concepto']);
            return !isset($anuladosSet[$key]);
        }));

        if (!empty($rows)) {
            DB::connection('rrhh')->table('planilla_movimientos')->insert($rows);
        }
    }

    /**
     * Actualiza manualmente las horas extras y/o nocturnidades de una línea de planilla.
     * Guarda el valor en horas_extras_override / horas_nocturnas_override (null = volver a automático).
     */
    public function updateHorasOverride(Request $request, int $id, int $empleadoId)
    {
        return DB::connection('rrhh')->transaction(function () use ($request, $id, $empleadoId) {
            $planilla = Planilla::findOrFail($id);
            if (in_array($planilla->estado, ['cerrada', 'pagada'])) {
                return response()->json(['message' => 'La planilla está cerrada.'], 422);
            }

            $linea = PlanillaLinea::where('planilla_id', $id)
                ->where('empleado_id', $empleadoId)
                ->firstOrFail();

            $data = $request->validate([
                'horas_extras'    => 'nullable|numeric|min:0',
                'horas_nocturnas' => 'nullable|numeric|min:0',
            ]);

            $salBase = (float) $linea->salario_base;

            if (array_key_exists('horas_extras', $data)) {
                $val = $data['horas_extras'] !== null ? (float) $data['horas_extras'] : null;
                $linea->horas_extras_override = $val;
                $horasExt = $val ?? (float) $linea->horas_extras;
                $linea->horas_extras       = round($horasExt, 2);
                $linea->monto_horas_extras = $this->svc->montoHorasExtras($salBase, $horasExt);
            }

            if (array_key_exists('horas_nocturnas', $data)) {
                $val = $data['horas_nocturnas'] !== null ? (float) $data['horas_nocturnas'] : null;
                $linea->horas_nocturnas_override = $val;
                $horasNoct = $val ?? (float) $linea->horas_nocturnas;
                $linea->horas_nocturnas     = round($horasNoct, 2);
                $linea->monto_nocturnidades = $this->svc->montoNocturnidades($salBase, $horasNoct);
            }

            $linea->salario_neto = $this->svc->recalcularNetoLinea($linea);
            $linea->save();

            $this->svc->recalcularTotalesPlanilla($planilla);

            return response()->json(['success' => true, 'linea' => $linea]);
        });
    }

    /**
     * Calcula el monto de vacaciones (30% del salario mensual) si el empleado
     * cumple su aniversario de ingreso dentro de la quincena dada (Art. 177 CT-SV).
     */
    private function calcularMontoVacaciones(array $emp, Carbon $fechaInicio, Carbon $fechaFin): float
    {
        $fechaIngreso = $emp['fecha_ingreso'] ?? null;
        if (!$fechaIngreso) return 0.0;

        $salBase = (float) ($emp['salario_base'] ?? 0);
        $ingreso = Carbon::parse($fechaIngreso);

        if ($ingreso->gte($fechaInicio)) return 0.0;

        $anioQ = $fechaInicio->year;
        try {
            $aniversario = Carbon::create($anioQ, $ingreso->month, $ingreso->day);
        } catch (\Exception $e) {
            $aniversario = Carbon::create($anioQ, $ingreso->month, 28);
        }

        // Prima = 30% sobre el salario de 15 días (Art. 177 CT-SV)
        return ($aniversario && $aniversario->between($fechaInicio, $fechaFin))
            ? round(($salBase / 2) * 0.30, 2)
            : 0.0;
    }

    /**
     * Recalcula y actualiza los totales de la planilla desde sus líneas actuales.
     */
    private function recalcularTotalesPlanilla(Planilla $planilla): void
    {
        $lineas = PlanillaLinea::where('planilla_id', $planilla->id)->get();

        $totals = [
            'total_salarios'   => $lineas->sum(fn($l) => (float) $l->salario_proporcional),
            'total_descuentos' => $lineas->sum(fn($l) => (float) $l->total_descuentos_empleado),
            'total_patronal'   => $lineas->sum(fn($l) => (float) $l->total_patronal),
            'total_neto'       => $lineas->sum(fn($l) => (float) $l->salario_neto),
        ];

        $planilla->update(array_map(fn($v) => round($v, 2), $totals));
    }
}
