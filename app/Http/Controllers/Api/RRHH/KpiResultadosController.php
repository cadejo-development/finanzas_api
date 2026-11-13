<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\KpiBonoPuesto;
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
                'meta_periodo'            => $r->meta_periodo,
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
     * GET /api/rrhh/kpi-resultados/resumen?periodo=YYYY-MM
     * Todos los resultados del período, agrupados por persona Y por KPI.
     */
    public function resumen(Request $request): JsonResponse
    {
        $periodo = $request->query('periodo');

        $query = KpiResultado::with('plantilla');
        if ($periodo) {
            $query->where('periodo', $periodo . '-01');
        }
        $resultados = $query->orderByDesc('periodo')->get();

        $empIds = $resultados->pluck('empleado_id')->unique()->values()->toArray();
        $empleados = [];
        if ($empIds) {
            $rows = DB::connection('pgsql')
                ->table('empleados as e')
                ->leftJoin('cargos as c', 'e.cargo_id', '=', 'c.id')
                ->leftJoin('sucursales as s', 'e.sucursal_id', '=', 's.id')
                ->whereIn('e.id', $empIds)
                ->selectRaw("e.id, TRIM(e.apellidos || ', ' || e.nombres) as nombre, c.nombre as cargo, s.nombre as sucursal")
                ->get();
            foreach ($rows as $r) {
                $empleados[$r->id] = $r;
            }
        }

        $porPersona = [];
        $porKpi     = [];

        foreach ($resultados as $r) {
            $emp       = $empleados[$r->empleado_id] ?? null;
            $plantilla = $r->plantilla;
            $periodoStr = $r->periodo->format('Y-m');

            $entradaEmp = [
                'kpi_plantilla_id' => $r->kpi_plantilla_id,
                'kpi_nombre'       => $plantilla?->nombre,
                'indicador'        => $plantilla?->unidad_medida,
                'meta'             => $plantilla?->monto_objetivo,
                'periodo'          => $periodoStr,
                'porcentaje'       => $r->porcentaje_cumplimiento,
                'valor_real'       => $r->valor_real,
                'monto_bono'       => $r->monto_bono,
            ];
            $entradaKpi = [
                'empleado_id'  => $r->empleado_id,
                'nombre'       => $emp?->nombre,
                'cargo'        => $emp?->cargo,
                'sucursal'     => $emp?->sucursal,
                'periodo'      => $periodoStr,
                'porcentaje'   => $r->porcentaje_cumplimiento,
                'valor_real'   => $r->valor_real,
                'monto_bono'   => $r->monto_bono,
            ];

            $ek = $r->empleado_id;
            if (!isset($porPersona[$ek])) {
                $porPersona[$ek] = [
                    'empleado_id' => $r->empleado_id,
                    'nombre'      => $emp?->nombre,
                    'cargo'       => $emp?->cargo,
                    'sucursal'    => $emp?->sucursal,
                    'kpis'        => [],
                    'total_bono'  => 0,
                ];
            }
            $porPersona[$ek]['kpis'][]     = $entradaEmp;
            $porPersona[$ek]['total_bono'] += $r->monto_bono;

            $kk = $r->kpi_plantilla_id;
            if (!isset($porKpi[$kk])) {
                $porKpi[$kk] = [
                    'kpi_plantilla_id' => $r->kpi_plantilla_id,
                    'nombre'           => $plantilla?->nombre,
                    'indicador'        => $plantilla?->unidad_medida,
                    'meta'             => $plantilla?->monto_objetivo,
                    'empleados'        => [],
                    'total_bono'       => 0,
                ];
            }
            $porKpi[$kk]['empleados'][]  = $entradaKpi;
            $porKpi[$kk]['total_bono']  += $r->monto_bono;
        }

        $periodosDisponibles = KpiResultado::selectRaw("TO_CHAR(periodo, 'YYYY-MM') as p")
            ->distinct()
            ->orderByDesc('p')
            ->pluck('p');

        return response()->json([
            'por_persona'          => array_values($porPersona),
            'por_kpi'              => array_values($porKpi),
            'periodos_disponibles' => $periodosDisponibles,
        ]);
    }

    /**
     * POST /api/rrhh/kpi-resultados/preview
     * Calcula bonos sin guardar.
     * - tipo_meta='fijo':     meta viene de plantilla.monto_objetivo
     * - tipo_meta='variable': meta viene del campo meta_periodo del request
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kpi_plantilla_id'  => 'required|integer|exists:pgsql.kpi_plantillas,id',
            'meta_periodo'      => 'nullable|numeric|min:0.01',
            'resultados'        => 'required|array|min:1',
            'resultados.*.empleado_id' => 'required|integer',
            'resultados.*.valor_real'  => 'required|numeric|min:0',
        ]);

        $plantilla = KpiPlantilla::with('escala')->findOrFail($data['kpi_plantilla_id']);
        $escala    = $plantilla->escala->toArray();
        $meta      = $this->resolverMeta($plantilla, $data['meta_periodo'] ?? null);
        $bonoBase  = (float) ($plantilla->monto_bono_base ?? $plantilla->monto_objetivo ?? 0);

        $opCumplimiento = $plantilla->operador_cumplimiento ?? '>=';

        $out = [];
        foreach ($data['resultados'] as $fila) {
            $valorReal = (float) $fila['valor_real'];
            $pct       = $this->calcularPct($valorReal, $meta, $opCumplimiento);
            $monBono   = $this->calcularBono($escala, $valorReal, $bonoBase);
            $out[] = [
                'empleado_id'             => $fila['empleado_id'],
                'porcentaje_cumplimiento' => $pct,
                'valor_real'              => $valorReal,
                'monto_bono'              => $monBono,
                'nivel_escala'            => $this->nivelEscala($escala, $valorReal),
            ];
        }

        return response()->json([
            'plantilla_id'   => $plantilla->id,
            'tipo_meta'      => $plantilla->tipo_meta,
            'meta'           => $meta,
            'resultados'     => $out,
            'total_bono'     => collect($out)->sum('monto_bono'),
        ]);
    }

    /**
     * POST /api/rrhh/kpi-resultados/aplicar
     * Guarda resultados Y crea bonificaciones aprobadas.
     * - tipo_meta='fijo':     meta viene de plantilla.monto_objetivo
     * - tipo_meta='variable': meta viene del campo meta_periodo del request
     */
    public function aplicar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kpi_plantilla_id' => 'required|integer|exists:pgsql.kpi_plantillas,id',
            'periodo'          => 'required|string|regex:/^\d{4}-\d{2}$/',
            'meta_periodo'     => 'nullable|numeric|min:0.01',
            'resultados'       => 'required|array|min:1',
            'resultados.*.empleado_id' => 'required|integer|exists:pgsql.empleados,id',
            'resultados.*.valor_real'  => 'required|numeric|min:0',
        ]);

        $plantilla = KpiPlantilla::with('escala')->findOrFail($data['kpi_plantilla_id']);
        $escala    = $plantilla->escala->toArray();
        $montoObj  = (float) ($plantilla->monto_bono_base ?? $plantilla->monto_objetivo ?? 0);
        $meta      = $this->resolverMeta($plantilla, $data['meta_periodo'] ?? null);
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
                'nombre'     => 'Bonificación KPI',
                'gravado'    => false,
                'activo'     => true,
                'created_at' => now(),
                'updated_at' => now(),
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

        $aprobadoPorId  = Auth::user()?->empleado?->id;
        $opCumplimiento = $plantilla->operador_cumplimiento ?? '>=';

        DB::connection('pgsql')->beginTransaction();
        try {
            $creados = [];
            foreach ($data['resultados'] as $fila) {
                $valorReal = (float) $fila['valor_real'];
                $pct     = $this->calcularPct($valorReal, $meta, $opCumplimiento);
                $monBono = $this->calcularBono($escala, $valorReal, $montoObj);

                // Insertar/actualizar resultado KPI
                $resultado = KpiResultado::updateOrCreate(
                    [
                        'kpi_plantilla_id' => $plantilla->id,
                        'empleado_id'      => $fila['empleado_id'],
                        'periodo'          => $periodo,
                    ],
                    [
                        'porcentaje_cumplimiento' => $pct,
                        'valor_real'              => (float) $fila['valor_real'],
                        'meta_periodo'            => $meta > 0 ? $meta : null,
                        'monto_bono'              => $monBono,
                        'aud_usuario'             => $audUser,
                    ]
                );

                // Crear/actualizar bonificación aprobada si el bono > 0
                $bonId = null;
                if ($monBono > 0) {
                    $nivel = $this->nivelEscala($escala, $valorReal);
                    $desc  = "KPI: {$plantilla->nombre} · Período {$data['periodo']} · {$pct}% cumplimiento" .
                             ($nivel ? " (tramo {$nivel['operador']}{$nivel['porcentaje_desde']})" : '');

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

        // ── Recalcular bono ponderado por puesto para cada empleado afectado ──
        $bonosPonderados = [];
        foreach ($data['resultados'] as $fila) {
            $bpResult = $this->recalcularBonoPonderado(
                $fila['empleado_id'],
                $data['periodo'],
                $tipoBonId,
                $estadoAprobada->id,
                $aprobadoPorId,
                $audUser,
                $fechaAplicar,
            );
            if ($bpResult !== null) {
                $bonosPonderados[$fila['empleado_id']] = $bpResult;
            }
        }

        return response()->json([
            'success'          => true,
            'periodo'          => $data['periodo'],
            'plantilla'        => $plantilla->nombre,
            'aplicados'        => count($creados),
            'total_bono'       => collect($creados)->sum('monto_bono'),
            'resultados'       => $creados,
            'bonos_ponderados' => array_values($bonosPonderados),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function calcularPct(float $valorReal, float $meta, string $op = '>='): float
    {
        if ($meta <= 0) return 0.0;
        // Para KPIs donde menor es mejor (<=), se invierte la proporción
        if ($op === '<=' && $valorReal > 0) {
            return round($meta / $valorReal * 100, 2);
        }
        return round($valorReal / $meta * 100, 2);
    }

    private function resolverMeta(KpiPlantilla $plantilla, ?float $metaPeriodo): float
    {
        if ($plantilla->tipo_meta === 'variable') {
            return (float) ($metaPeriodo ?? 0);
        }
        return (float) ($plantilla->monto_objetivo ?? 0);
    }

    // Compara el valor real directamente contra los umbrales de la escala.
    // La escala define tramos por valor absoluto (ej. "≥ 9.21 → 100 pts").
    private function calcularBono(array $escala, float $valorReal, float $montoObjetivo): float
    {
        if (empty($escala)) return 0.0;

        $ops         = collect($escala)->pluck('operador')->filter()->unique();
        $bajaEsMejor = $ops->intersect(['<=', '<'])->isNotEmpty();
        $sorted      = $bajaEsMejor
            ? collect($escala)->sortBy('porcentaje_desde')
            : collect($escala)->sortByDesc('porcentaje_desde');

        foreach ($sorted as $nivel) {
            $op        = $nivel['operador'] ?? '>=';
            $threshold = (float) ($nivel['porcentaje_desde'] ?? 0);
            $match     = match ($op) {
                '>='    => $valorReal >= $threshold,
                '>'     => $valorReal > $threshold,
                '<='    => $valorReal <= $threshold,
                '<'     => $valorReal < $threshold,
                default => $valorReal >= $threshold,
            };
            if ($match) {
                if (($nivel['tipo'] ?? '') === 'porcentaje_bono') {
                    return round($montoObjetivo * (float) ($nivel['valor'] ?? 0) / 100, 2);
                }
                return round((float) ($nivel['valor'] ?? 0), 2);
            }
        }
        return 0.0;
    }

    private function nivelEscala(array $escala, float $valorReal): ?array
    {
        if (empty($escala)) return null;

        $ops         = collect($escala)->pluck('operador')->filter()->unique();
        $bajaEsMejor = $ops->intersect(['<=', '<'])->isNotEmpty();
        $sorted      = $bajaEsMejor
            ? collect($escala)->sortBy('porcentaje_desde')
            : collect($escala)->sortByDesc('porcentaje_desde');

        foreach ($sorted as $nivel) {
            $op        = $nivel['operador'] ?? '>=';
            $threshold = (float) ($nivel['porcentaje_desde'] ?? 0);
            $match     = match ($op) {
                '>='    => $valorReal >= $threshold,
                '>'     => $valorReal > $threshold,
                '<='    => $valorReal <= $threshold,
                '<'     => $valorReal < $threshold,
                default => $valorReal >= $threshold,
            };
            if ($match) {
                return (array) $nivel;
            }
        }
        return null;
    }

    /**
     * Recalcula el bono ponderado de un empleado para un período dado.
     * Suma (peso_kpi × pct_cumplimiento) para todos sus KPIs registrados en ese período.
     * Si el cumplimiento ponderado >= umbral_minimo del puesto → crea/actualiza bonificación ponderada.
     * Si no llega → elimina la bonificación ponderada si existía.
     * Retorna null si el puesto del empleado no tiene kpi_bono_puesto configurado.
     */
    private function recalcularBonoPonderado(
        int $empleadoId,
        string $periodo,
        int $tipoBonId,
        int $estadoAprobadaId,
        ?int $aprobadoPorId,
        string $audUser,
        string $fechaAplicar,
    ): ?array {
        // Obtener cargo y sucursal del empleado
        $emp = DB::connection('pgsql')
            ->table('empleados')
            ->where('id', $empleadoId)
            ->select('cargo_id', 'sucursal_id')
            ->first();

        if (!$emp?->cargo_id) return null;

        // Buscar configuración de bono: primero cargo+sucursal, luego solo cargo (null sucursal)
        $bonoPuesto = KpiBonoPuesto::where('cargo_id', $emp->cargo_id)
            ->where(function ($q) use ($emp) {
                $q->where('sucursal_id', $emp->sucursal_id)
                  ->orWhereNull('sucursal_id');
            })
            ->orderByRaw('sucursal_id IS NULL ASC') // preferir la específica de sucursal
            ->first();

        if (!$bonoPuesto) return null;

        // Obtener todos los KPIs asignados al cargo del empleado con sus pesos
        $kpisDelCargo = DB::connection('pgsql')
            ->table('kpi_plantilla_cargos as kpc')
            ->join('kpi_plantillas as kp', 'kp.id', '=', 'kpc.kpi_plantilla_id')
            ->where('kpc.cargo_id', $emp->cargo_id)
            ->where('kp.activo', true)
            ->whereNotNull('kpc.peso')
            ->where('kpc.peso', '>', 0)
            ->select('kpc.kpi_plantilla_id', 'kpc.peso')
            ->get();

        if ($kpisDelCargo->isEmpty()) return null;

        $periodoDate = $periodo . '-01';

        // Obtener resultados registrados del empleado en este período
        $resultadosRegistrados = KpiResultado::where('empleado_id', $empleadoId)
            ->where('periodo', $periodoDate)
            ->whereIn('kpi_plantilla_id', $kpisDelCargo->pluck('kpi_plantilla_id'))
            ->get()
            ->keyBy('kpi_plantilla_id');

        // Calcular cumplimiento ponderado: Σ(peso × pct) / peso_total_del_cargo
        $pesoTotal       = $kpisDelCargo->sum('peso');
        $cumplimientoSum = 0.0;

        foreach ($kpisDelCargo as $kpi) {
            $resultado = $resultadosRegistrados->get($kpi->kpi_plantilla_id);
            $pct       = $resultado ? (float) $resultado->porcentaje_cumplimiento : 0.0;
            $cumplimientoSum += ($kpi->peso / $pesoTotal) * $pct;
        }

        $cumplimientoPonderado = round($cumplimientoSum, 2);
        $alcanzaUmbral         = $cumplimientoPonderado >= $bonoPuesto->umbral_minimo;
        $montoBono             = $alcanzaUmbral ? $bonoPuesto->monto_bono : 0.0;

        // Buscar si ya existe un bono ponderado para este empleado/período
        $bonExistente = DB::connection('pgsql')
            ->table('bonificaciones')
            ->where('empleado_id', $empleadoId)
            ->where('tipo_bonificacion_id', $tipoBonId)
            ->whereRaw("descripcion LIKE ?", ["%[ponderado] Período {$periodo}%"])
            ->first();

        $desc = "[ponderado] Período {$periodo} · {$cumplimientoPonderado}% cumplimiento ponderado";

        if ($alcanzaUmbral) {
            if ($bonExistente) {
                DB::connection('pgsql')->table('bonificaciones')
                    ->where('id', $bonExistente->id)
                    ->update([
                        'monto'           => $montoBono,
                        'descripcion'     => $desc,
                        'estado_id'       => $estadoAprobadaId,
                        'aprobado_por_id' => $aprobadoPorId,
                        'aud_usuario'     => $audUser,
                        'updated_at'      => now(),
                    ]);
            } else {
                DB::connection('pgsql')->table('bonificaciones')->insert([
                    'empleado_id'          => $empleadoId,
                    'tipo_bonificacion_id'  => $tipoBonId,
                    'estado_id'            => $estadoAprobadaId,
                    'monto'                => $montoBono,
                    'descripcion'          => $desc,
                    'fecha_aplicar'        => $fechaAplicar,
                    'solicitado_por_id'    => $aprobadoPorId ?? $empleadoId,
                    'aprobado_por_id'      => $aprobadoPorId,
                    'aud_usuario'          => $audUser,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            }
        } elseif ($bonExistente) {
            // No alcanza el umbral: eliminar el bono ponderado si ya existía
            DB::connection('pgsql')->table('bonificaciones')->where('id', $bonExistente->id)->delete();
        }

        return [
            'empleado_id'            => $empleadoId,
            'cumplimiento_ponderado' => $cumplimientoPonderado,
            'umbral_minimo'          => $bonoPuesto->umbral_minimo,
            'alcanza_umbral'         => $alcanzaUmbral,
            'monto_bono_ponderado'   => $montoBono,
        ];
    }
}
