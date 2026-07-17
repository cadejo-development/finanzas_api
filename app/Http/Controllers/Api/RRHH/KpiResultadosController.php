<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\KpiPlantilla;
use App\Models\KpiResultado;
use App\Models\RRHH\Bonificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class KpiResultadosController extends Controller
{
    /**
     * GET /api/rrhh/kpi-resultados?plantilla_id=X
     * Lista períodos aplicados con sus resultados.
     */
    public function index(Request $request): JsonResponse
    {
        $plantillaId = $request->query('plantilla_id');
        if (!$plantillaId) {
            return response()->json(['error' => 'plantilla_id requerido'], 422);
        }

        $resultados = KpiResultado::where('kpi_plantilla_id', $plantillaId)
            ->orderByDesc('periodo')
            ->get();

        // Enriquecer con nombre de empleado
        $empIds = $resultados->pluck('empleado_id')->unique()->values()->toArray();
        $empleados = [];
        if ($empIds) {
            $rows = DB::connection('pgsql')
                ->table('empleados as e')
                ->leftJoin('cargos as c', 'e.cargo_id', '=', 'c.id')
                ->leftJoin('sucursales as s', 'e.sucursal_id', '=', 's.id')
                ->whereIn('e.id', $empIds)
                ->selectRaw("e.id, TRIM(e.nombres || ' ' || e.apellidos) as nombre, c.nombre as cargo, s.nombre as sucursal")
                ->get();
            foreach ($rows as $r) {
                $empleados[$r->id] = $r;
            }
        }

        // Agrupar por período
        $periodos = [];
        foreach ($resultados as $r) {
            $p = $r->periodo->format('Y-m');
            if (!isset($periodos[$p])) {
                $periodos[$p] = ['periodo' => $p, 'total_bono' => 0, 'empleados' => 0, 'resultados' => []];
            }
            $emp = $empleados[$r->empleado_id] ?? null;
            $periodos[$p]['resultados'][] = [
                'id'                      => $r->id,
                'empleado_id'             => $r->empleado_id,
                'empleado_nombre'         => $emp?->nombre,
                'cargo'                   => $emp?->cargo,
                'sucursal'                => $emp?->sucursal,
                'porcentaje_cumplimiento' => $r->porcentaje_cumplimiento,
                'valor_real'              => $r->valor_real,
                'monto_bono'              => $r->monto_bono,
                'bonificacion_id'         => $r->bonificacion_id,
                'created_at'              => $r->created_at,
            ];
            $periodos[$p]['total_bono'] += $r->monto_bono;
            $periodos[$p]['empleados']++;
        }

        return response()->json(array_values($periodos));
    }

    /**
     * POST /api/rrhh/kpi-resultados/preview
     * Calcula bonos sin guardar.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kpi_plantilla_id' => 'required|integer|exists:pgsql.kpi_plantillas,id',
            'resultados'       => 'required|array|min:1',
            'resultados.*.empleado_id'             => 'required|integer',
            'resultados.*.porcentaje_cumplimiento' => 'required|numeric|min:0|max:999',
            'resultados.*.valor_real'              => 'nullable|numeric|min:0',
        ]);

        $plantilla = KpiPlantilla::with('escala')->findOrFail($data['kpi_plantilla_id']);
        $escala    = $plantilla->escala->toArray();
        $montoObj  = (float) ($plantilla->monto_objetivo ?? 0);

        $out = [];
        foreach ($data['resultados'] as $fila) {
            $pct      = (float) $fila['porcentaje_cumplimiento'];
            $monBono  = $this->calcularBono($escala, $pct, $montoObj);
            $out[] = [
                'empleado_id'             => $fila['empleado_id'],
                'porcentaje_cumplimiento' => $pct,
                'valor_real'              => $fila['valor_real'] ?? null,
                'monto_bono'              => $monBono,
                'nivel_escala'            => $this->nivelEscala($escala, $pct),
            ];
        }

        return response()->json([
            'plantilla_id'   => $plantilla->id,
            'monto_objetivo' => $montoObj,
            'resultados'     => $out,
            'total_bono'     => collect($out)->sum('monto_bono'),
        ]);
    }

    /**
     * POST /api/rrhh/kpi-resultados/aplicar
     * Guarda resultados Y crea bonificaciones aprobadas.
     */
    public function aplicar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kpi_plantilla_id' => 'required|integer|exists:pgsql.kpi_plantillas,id',
            'periodo'          => 'required|string|regex:/^\d{4}-\d{2}$/',
            'resultados'       => 'required|array|min:1',
            'resultados.*.empleado_id'             => 'required|integer|exists:pgsql.empleados,id',
            'resultados.*.porcentaje_cumplimiento' => 'required|numeric|min:0|max:999',
            'resultados.*.valor_real'              => 'nullable|numeric|min:0',
        ]);

        $plantilla = KpiPlantilla::with('escala')->findOrFail($data['kpi_plantilla_id']);
        $escala    = $plantilla->escala->toArray();
        $montoObj  = (float) ($plantilla->monto_objetivo ?? 0);
        $periodo   = $data['periodo'] . '-01'; // 2026-07-01
        $audUser   = Auth::user()?->email ?? 'sistema';
        $fechaAplicar = date('Y-m-d', strtotime('last day of ' . $data['periodo']));

        // Tipo de bonificación KPI (busca o crea)
        $tipoBono = DB::connection('pgsql')
            ->table('tipos_bonificacion')
            ->where('nombre', 'Bonificación KPI')
            ->first();
        if (!$tipoBono) {
            $tipoBonId = DB::connection('pgsql')->table('tipos_bonificacion')->insertGetId([
                'nombre'      => 'Bonificación KPI',
                'descripcion' => 'Generada automáticamente por cumplimiento de KPI',
                'activo'      => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } else {
            $tipoBonId = $tipoBono->id;
        }

        // Estado "Aprobada"
        $estadoAprobada = DB::connection('pgsql')
            ->table('estados_bonificacion')
            ->where('nombre', 'Aprobada')
            ->first();
        if (!$estadoAprobada) {
            return response()->json(['error' => 'No se encontró estado "Aprobada" en estados_bonificacion'], 500);
        }

        $aprobadoPorId = Auth::user()?->empleado?->id;

        DB::connection('pgsql')->beginTransaction();
        try {
            $creados = [];
            foreach ($data['resultados'] as $fila) {
                $pct     = (float) $fila['porcentaje_cumplimiento'];
                $monBono = $this->calcularBono($escala, $pct, $montoObj);

                // Insertar/actualizar resultado KPI
                $resultado = KpiResultado::updateOrCreate(
                    [
                        'kpi_plantilla_id' => $plantilla->id,
                        'empleado_id'      => $fila['empleado_id'],
                        'periodo'          => $periodo,
                    ],
                    [
                        'porcentaje_cumplimiento' => $pct,
                        'valor_real'              => $fila['valor_real'] ?? null,
                        'monto_bono'              => $monBono,
                        'aud_usuario'             => $audUser,
                    ]
                );

                // Crear/actualizar bonificación aprobada si el bono > 0
                $bonId = null;
                if ($monBono > 0) {
                    $nivel = $this->nivelEscala($escala, $pct);
                    $desc  = "KPI: {$plantilla->nombre} · Período {$data['periodo']} · {$pct}% cumplimiento" .
                             ($nivel ? " (nivel ≥{$nivel['porcentaje_desde']}%)" : '');

                    if ($resultado->bonificacion_id) {
                        // Actualizar si ya existe
                        Bonificacion::where('id', $resultado->bonificacion_id)->update([
                            'monto'            => $monBono,
                            'descripcion'      => $desc,
                            'estado_id'        => $estadoAprobada->id,
                            'aprobado_por_id'  => $aprobadoPorId,
                            'aud_usuario'      => $audUser,
                        ]);
                        $bonId = $resultado->bonificacion_id;
                    } else {
                        $bon = Bonificacion::create([
                            'empleado_id'         => $fila['empleado_id'],
                            'tipo_bonificacion_id'=> $tipoBonId,
                            'estado_id'           => $estadoAprobada->id,
                            'monto'               => $monBono,
                            'descripcion'         => $desc,
                            'fecha_aplicar'       => $fechaAplicar,
                            'solicitado_por_id'   => $aprobadoPorId ?? $fila['empleado_id'],
                            'aprobado_por_id'     => $aprobadoPorId,
                            'aud_usuario'         => $audUser,
                        ]);
                        $bonId = $bon->id;
                    }
                }

                $resultado->update(['bonificacion_id' => $bonId]);

                $creados[] = [
                    'empleado_id'             => $fila['empleado_id'],
                    'porcentaje_cumplimiento' => $pct,
                    'monto_bono'              => $monBono,
                    'bonificacion_id'         => $bonId,
                ];
            }

            DB::connection('pgsql')->commit();
        } catch (\Throwable $e) {
            DB::connection('pgsql')->rollBack();
            throw $e;
        }

        return response()->json([
            'success'      => true,
            'periodo'      => $data['periodo'],
            'plantilla'    => $plantilla->nombre,
            'aplicados'    => count($creados),
            'total_bono'   => collect($creados)->sum('monto_bono'),
            'resultados'   => $creados,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function calcularBono(array $escala, float $porcentaje, float $montoObjetivo): float
    {
        $sorted = collect($escala)->sortByDesc('porcentaje_desde');
        foreach ($sorted as $nivel) {
            if ($porcentaje >= (float) $nivel['porcentaje_desde']) {
                if ($nivel['tipo'] === 'porcentaje_bono') {
                    return round($montoObjetivo * (float) $nivel['valor'] / 100, 2);
                }
                return round((float) $nivel['valor'], 2);
            }
        }
        return 0.0;
    }

    private function nivelEscala(array $escala, float $porcentaje): ?array
    {
        $sorted = collect($escala)->sortByDesc('porcentaje_desde');
        foreach ($sorted as $nivel) {
            if ($porcentaje >= (float) $nivel['porcentaje_desde']) {
                return (array) $nivel;
            }
        }
        return null;
    }
}
