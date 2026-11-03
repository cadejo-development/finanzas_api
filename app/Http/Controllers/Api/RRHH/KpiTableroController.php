<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\KpiPlantilla;
use App\Models\KpiResultado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KpiTableroController extends Controller
{
    /**
     * GET /api/rrhh/kpi-tablero?periodo=YYYY-MM&sucursal_id=X
     * Dashboard admin: cumplimiento general, por sucursal, por puesto.
     */
    public function index(Request $request): JsonResponse
    {
        $periodo    = $request->query('periodo', date('Y-m'));
        $sucursalId = $request->query('sucursal_id');
        $periodoFecha = $periodo . '-01';

        // Cargar todos los KPIs activos con sus cargos y la sucursal
        $plantillas = KpiPlantilla::with(['cargos:id,nombre', 'sucursal:id,nombre'])
            ->withCount('cargos')
            ->where('activo', true)
            ->when($sucursalId, fn($q) => $q->where('sucursal_id', (int) $sucursalId))
            ->get();

        $kpiIds = $plantillas->pluck('id')->toArray();

        // Resultados del período
        $resultados = empty($kpiIds) ? collect() : KpiResultado::whereIn('kpi_plantilla_id', $kpiIds)
            ->where('periodo', $periodoFecha)
            ->get();

        // Empleados afectados por estos KPIs (cargo+sucursal)
        $empleadosPorKpi = $this->empleadosPorKpi($plantillas);

        // Calcular cumplimiento por KPI
        $cumplPorKpi = [];
        foreach ($plantillas as $p) {
            $kpiResultados = $resultados->where('kpi_plantilla_id', $p->id);
            $totalEmpleados = count($empleadosPorKpi[$p->id] ?? []);
            $registrados    = $kpiResultados->count();
            $promedio       = $registrados > 0 ? round($kpiResultados->avg('porcentaje_cumplimiento'), 1) : null;

            foreach ($p->cargos as $cargo) {
                $cumplPorKpi[$p->id] = [
                    'kpi_id'           => $p->id,
                    'nombre'           => $p->nombre,
                    'sucursal_id'      => $p->sucursal_id,
                    'sucursal_nombre'  => $p->sucursal?->nombre ?? 'Sin sucursal',
                    'cargo_id'         => $cargo->id,
                    'cargo_nombre'     => $cargo->nombre,
                    'peso'             => $cargo->pivot->peso,
                    'tipo_meta'        => $p->tipo_meta ?? 'fijo',
                    'monto_objetivo'   => $p->monto_objetivo,
                    'total_empleados'  => $totalEmpleados,
                    'registrados'      => $registrados,
                    'promedio_cumpl'   => $promedio,
                    'tiene_bono'       => $p->tiene_bono,
                    'monto_bono_base'  => $p->monto_bono_base,
                    'pendiente_registro' => $totalEmpleados > $registrados,
                ];
                break; // Solo usamos el primer cargo (un KPI tiene un cargo en el nuevo modelo)
            }
        }

        // Stats globales
        $totalPuestos      = $resultados->groupBy('empleado_id')->count();
        $conCumpl100       = $resultados->where('porcentaje_cumplimiento', '>=', 100)->count();
        $promGlobal        = $resultados->count() > 0
            ? round($resultados->avg('porcentaje_cumplimiento'), 1)
            : null;

        $totalKpisActivos  = count($kpiIds);
        $kpisRegistrados   = $resultados->pluck('kpi_plantilla_id')->unique()->count();

        // Cumplimiento por sucursal
        $porSucursal = $this->cumplimientoPorSucursal($plantillas, $resultados, $empleadosPorKpi, $sucursalId);

        // KPIs que requieren atención (cumplimiento < 75 o sin registrar)
        $requierenAtencion = collect($cumplPorKpi)
            ->filter(fn($k) => $k['promedio_cumpl'] !== null && $k['promedio_cumpl'] < 75)
            ->sortBy('promedio_cumpl')
            ->values();

        // Resultados agrupados por cargo+sucursal para la tabla de puestos
        $porPuesto = $this->resultadosPorPuesto($plantillas, $resultados, $empleadosPorKpi, $periodoFecha);

        return response()->json([
            'periodo'             => $periodo,
            'stats' => [
                'promedio_cumplimiento' => $promGlobal,
                'total_kpis'            => $totalKpisActivos,
                'kpis_registrados'      => $kpisRegistrados,
                'puestos_con_resultado' => $totalPuestos,
            ],
            'por_sucursal'        => $porSucursal,
            'requieren_atencion'  => $requierenAtencion,
            'por_puesto'          => $porPuesto,
        ]);
    }

    /**
     * GET /api/rrhh/kpi-pendientes?periodo=YYYY-MM
     * Para el cargo responsable de registrar: muestra los KPIs que debe llenar.
     */
    public function pendientes(Request $request): JsonResponse
    {
        $user    = $request->user();
        $periodo = $request->query('periodo', date('Y-m'));
        $periodoFecha = $periodo . '-01';

        // Obtener cargo_id del usuario autenticado
        $cargoId = DB::connection('pgsql')
            ->table('empleados')
            ->where('user_id', $user->id)
            ->value('cargo_id');

        // KPIs donde este cargo es el responsable de registrar
        $plantillas = KpiPlantilla::with(['cargos:id,nombre', 'sucursal:id,nombre', 'escala'])
            ->where('activo', true)
            ->where('cargo_responsable_id', $cargoId)
            ->get();

        if ($plantillas->isEmpty()) {
            return response()->json([
                'periodo'    => $periodo,
                'total'      => 0,
                'registrados'=> 0,
                'pendientes' => 0,
                'fecha_limite'=> null,
                'dias_quedan' => null,
                'grupos'     => [],
            ]);
        }

        $kpiIds = $plantillas->pluck('id')->toArray();

        // Resultados ya registrados en este período para estos KPIs
        $resultados = KpiResultado::whereIn('kpi_plantilla_id', $kpiIds)
            ->where('periodo', $periodoFecha)
            ->get()
            ->keyBy(fn($r) => $r->kpi_plantilla_id . ':' . $r->empleado_id);

        // Empleados de cada KPI
        $empleadosPorKpi = $this->empleadosPorKpi($plantillas);

        // Calcular fecha límite: último día hábil del mes siguiente
        $fechaLimite = date('Y-m-d', strtotime('last day of ' . $periodo));
        $diasQuedan  = max(0, (int) ceil((strtotime($fechaLimite) - time()) / 86400));

        // Contar totales
        $totalPares    = 0;
        $registradosCt = 0;

        $grupos = [];
        foreach ($plantillas as $p) {
            $empleados = $empleadosPorKpi[$p->id] ?? [];
            $sucursal  = $p->sucursal?->nombre ?? 'Sin sucursal';
            $cargo     = $p->cargos->first()?->nombre ?? 'Sin cargo';
            $cargoId2  = $p->cargos->first()?->id;
            $grupoKey  = $sucursal . '|' . ($cargoId2 ?? 'x');

            if (!isset($grupos[$grupoKey])) {
                $grupos[$grupoKey] = [
                    'sucursal_nombre' => $sucursal,
                    'cargo_nombre'    => $cargo,
                    'cargo_id'        => $cargoId2,
                    'kpis'            => [],
                ];
            }

            $kpiPares = [];
            foreach ($empleados as $emp) {
                $key      = $p->id . ':' . $emp->id;
                $resultado = $resultados->get($key);
                $registrado = $resultado !== null;
                $totalPares++;
                if ($registrado) $registradosCt++;

                $kpiPares[] = [
                    'empleado_id'             => $emp->id,
                    'empleado_nombre'         => $emp->nombre,
                    'registrado'              => $registrado,
                    'porcentaje_cumplimiento' => $resultado?->porcentaje_cumplimiento,
                    'valor_real'              => $resultado?->valor_real,
                    'comentario'              => $resultado?->comentario,
                ];
            }

            // Agrupar empleados en el KPI
            $grupos[$grupoKey]['kpis'][] = [
                'kpi_id'           => $p->id,
                'nombre'           => $p->nombre,
                'descripcion'      => $p->descripcion,
                'unidad_medida'    => $p->unidad_medida,
                'tipo_meta'        => $p->tipo_meta ?? 'fijo',
                'monto_objetivo'   => $p->monto_objetivo,
                'tipo_evaluacion'  => $p->tipo_evaluacion ?? 'comparar_meta',
                'evidencia_tipo'   => $p->evidencia_tipo ?? 'comentario_opcional',
                'fuente_informacion' => $p->fuente_informacion,
                'operador_cumplimiento' => $p->operador_cumplimiento ?? '>=',
                'escala'           => $p->escala->map(fn($e) => [
                    'porcentaje_desde' => (float) $e->porcentaje_desde,
                    'descripcion'      => $e->descripcion,
                    'operador'         => $e->operador ?? '>=',
                ])->values(),
                'empleados'        => $kpiPares,
                'total'            => count($kpiPares),
                'registrados'      => collect($kpiPares)->where('registrado', true)->count(),
            ];
        }

        return response()->json([
            'periodo'      => $periodo,
            'total'        => $totalPares,
            'registrados'  => $registradosCt,
            'pendientes'   => $totalPares - $registradosCt,
            'fecha_limite' => $fechaLimite,
            'dias_quedan'  => $diasQuedan,
            'grupos'       => array_values($grupos),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function empleadosPorKpi($plantillas): array
    {
        $map = [];
        foreach ($plantillas as $p) {
            $cargoIds = $p->cargos->pluck('id')->toArray();
            if (empty($cargoIds)) {
                $map[$p->id] = [];
                continue;
            }
            $query = DB::connection('pgsql')
                ->table('empleados as e')
                ->join('cargos as c', 'e.cargo_id', '=', 'c.id')
                ->where('e.activo', true)
                ->whereIn('e.cargo_id', $cargoIds)
                ->selectRaw("e.id, TRIM(e.nombres || ' ' || e.apellidos) as nombre, e.sucursal_id");

            if ($p->sucursal_id) {
                $query->where('e.sucursal_id', $p->sucursal_id);
            }
            $map[$p->id] = $query->get()->toArray();
        }
        return $map;
    }

    private function cumplimientoPorSucursal($plantillas, $resultados, $empleadosPorKpi, $sucursalId): array
    {
        $sucursales = DB::connection('pgsql')->table('sucursales')->where('activo', true)->orderBy('nombre')->get();

        $out = [];
        foreach ($sucursales as $s) {
            if ($sucursalId && $s->id != $sucursalId) continue;
            $kpisDeSuc = $plantillas->where('sucursal_id', $s->id);
            if ($kpisDeSuc->isEmpty()) continue;

            $kpiIdsSuc = $kpisDeSuc->pluck('id')->toArray();
            $resSuc    = $resultados->whereIn('kpi_plantilla_id', $kpiIdsSuc);
            $prom      = $resSuc->count() > 0 ? round($resSuc->avg('porcentaje_cumplimiento'), 1) : null;

            $totalEmp = 0;
            foreach ($kpisDeSuc as $k) {
                $totalEmp += count($empleadosPorKpi[$k->id] ?? []);
            }

            $out[] = [
                'sucursal_id'    => $s->id,
                'sucursal_nombre'=> $s->nombre,
                'promedio'       => $prom,
                'kpis_total'     => $kpisDeSuc->count(),
                'kpis_registrados'=> $resSuc->pluck('kpi_plantilla_id')->unique()->count(),
                'empleados_total' => $totalEmp,
            ];
        }
        return $out;
    }

    private function resultadosPorPuesto($plantillas, $resultados, $empleadosPorKpi, string $periodoFecha): array
    {
        // Agrupar por cargo_id+sucursal_id
        $grupos = [];

        foreach ($plantillas as $p) {
            $cargoKey = ($p->cargos->first()?->id ?? 0) . '_' . ($p->sucursal_id ?? 0);
            if (!isset($grupos[$cargoKey])) {
                $grupos[$cargoKey] = [
                    'cargo_id'       => $p->cargos->first()?->id,
                    'cargo_nombre'   => $p->cargos->first()?->nombre ?? 'Sin cargo',
                    'sucursal_id'    => $p->sucursal_id,
                    'sucursal_nombre'=> $p->sucursal?->nombre ?? 'Sin sucursal',
                    'kpis'           => [],
                    'nota_global'    => null,
                    'bono'           => null,
                    'tiene_bono'     => false,
                    'pendiente_registro' => false,
                ];
            }

            $resDep     = $resultados->where('kpi_plantilla_id', $p->id);
            $empCount   = count($empleadosPorKpi[$p->id] ?? []);
            $regCount   = $resDep->count();
            $prom       = $regCount > 0 ? round($resDep->avg('porcentaje_cumplimiento'), 1) : null;
            $peso       = $p->cargos->first()?->pivot->peso ?? null;

            $grupos[$cargoKey]['kpis'][] = [
                'kpi_id'         => $p->id,
                'nombre'         => $p->nombre,
                'peso'           => $peso,
                'promedio'       => $prom,
                'registrados'    => $regCount,
                'total'          => $empCount,
            ];

            if (!$p->tiene_bono) {
                // noop
            } else {
                $grupos[$cargoKey]['tiene_bono'] = true;
            }

            if ($regCount < $empCount) {
                $grupos[$cargoKey]['pendiente_registro'] = true;
            }
        }

        // Calcular nota global ponderada por peso
        foreach ($grupos as &$g) {
            $notaTotal = 0;
            $pesoTotal = 0;
            foreach ($g['kpis'] as $k) {
                if ($k['promedio'] !== null && $k['peso'] !== null) {
                    $notaTotal += $k['promedio'] * ((float) $k['peso'] / 100);
                    $pesoTotal += (float) $k['peso'];
                }
            }
            $g['nota_global'] = $pesoTotal > 0 ? round($notaTotal, 1) : null;
        }

        return array_values($grupos);
    }
}
