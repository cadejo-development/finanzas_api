<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\PropinaDetalle;
use App\Models\RRHH\PropinaPeriodo;
use App\Services\RRHH\PropinaCalculatorService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PropinasController extends RRHHBaseController
{
    public function __construct(private PropinaCalculatorService $calculator) {}

    // ─── Lista de períodos ────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = PropinaPeriodo::orderByDesc('anio')->orderByDesc('mes')->orderByDesc('quincena');

        if ($request->filled('sucursal_id')) $query->where('sucursal_id', $request->sucursal_id);
        if ($request->filled('anio'))        $query->where('anio', $request->anio);
        if ($request->filled('estado'))      $query->where('estado', $request->estado);

        $periodos = $query->paginate(30);

        $sucIds = collect($periodos->items())->pluck('sucursal_id')->unique();
        $sucs   = DB::connection('pgsql')->table('sucursales')->whereIn('id', $sucIds)->pluck('nombre', 'id');

        $data = collect($periodos->items())->map(fn($p) => array_merge($p->toArray(), [
            'sucursal_nombre' => $sucs[$p->sucursal_id] ?? null,
        ]));

        return response()->json([
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'total'        => $periodos->total(),
                'per_page'     => $periodos->perPage(),
                'current_page' => $periodos->currentPage(),
                'last_page'    => $periodos->lastPage(),
            ],
        ]);
    }

    // ─── Detalle de un período ────────────────────────────────────────────────

    public function show(int $id): JsonResponse
    {
        $periodo = PropinaPeriodo::with('detalles')->findOrFail($id);

        $sucursal = DB::connection('pgsql')
            ->table('sucursales')->where('id', $periodo->sucursal_id)->first(['id', 'nombre']);

        // Enriquecer detalles con nombre y cargo
        $empIds = $periodo->detalles->pluck('empleado_id')->unique();
        $emps   = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('cargos as c', 'c.id', '=', 'e.cargo_id')
            ->whereIn('e.id', $empIds)
            ->select('e.id', 'e.nombres', 'e.apellidos', 'c.nombre as cargo_nombre', 'e.cargo_id')
            ->get()->keyBy('id');

        $detalles = $periodo->detalles->sortBy(fn($d) => $emps[$d->empleado_id]?->apellidos ?? '')->map(fn($d) => array_merge($d->toArray(), [
            'nombre_completo' => isset($emps[$d->empleado_id])
                ? trim($emps[$d->empleado_id]->nombres . ' ' . $emps[$d->empleado_id]->apellidos)
                : 'N/D',
            'cargo_nombre' => $emps[$d->empleado_id]?->cargo_nombre,
        ]))->values();

        return response()->json([
            'success' => true,
            'data'    => array_merge($periodo->toArray(), [
                'sucursal_nombre' => $sucursal?->nombre,
                'detalles'        => $detalles,
            ]),
        ]);
    }

    // ─── Crear período ────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sucursal_id' => 'required|integer',
            'anio'        => 'required|integer|min:2024',
            'mes'         => 'required|integer|min:1|max:12',
            'quincena'    => 'required|integer|in:1,2',
        ]);

        // Calcular fechas del período
        $anio = $validated['anio'];
        $mes  = $validated['mes'];
        $q    = $validated['quincena'];

        [$fechaInicio, $fechaFin] = $this->rangoQuincena($anio, $mes, $q);

        // Obtener config para días quincena
        $config = DB::connection('rrhh')
            ->table('propina_config_sucursal')
            ->where('sucursal_id', $validated['sucursal_id'])
            ->first();

        $diasQ = $q === 1
            ? ($config?->dias_quincena_1 ?? 15)
            : ($config?->dias_quincena_2 ?? 15);

        $periodo = PropinaPeriodo::create([
            'sucursal_id'  => $validated['sucursal_id'],
            'anio'         => $anio,
            'mes'          => $mes,
            'quincena'     => $q,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin'    => $fechaFin,
            'dias_quincena' => $diasQ,
            'estado'       => 'borrador',
            'elaborado_por' => auth()->id(),
        ]);

        return response()->json(['success' => true, 'data' => $periodo], 201);
    }

    // ─── Ingresar ventas y calcular ───────────────────────────────────────────

    public function calcular(Request $request, int $id): JsonResponse
    {
        $periodo = PropinaPeriodo::findOrFail($id);

        if ($periodo->estado === 'aprobado' || $periodo->estado === 'integrado') {
            return response()->json(['success' => false, 'message' => 'El período ya fue aprobado.'], 422);
        }

        $validated = $request->validate([
            'venta_quincena'            => 'required|numeric|min:0',
            'propina_total_recolectada' => 'required|numeric|min:0',
            'dias_quincena'             => 'sometimes|integer|min:1|max:16',
        ]);

        $periodo->update($validated);

        $periodo = $this->calculator->calcularPeriodo($periodo);

        return response()->json(['success' => true, 'data' => $periodo->load('detalles')]);
    }

    // ─── Editar detalle individual (override manual) ──────────────────────────

    public function updateDetalle(Request $request, int $periodoId, int $detalleId): JsonResponse
    {
        $periodo = PropinaPeriodo::findOrFail($periodoId);
        if ($periodo->estado === 'aprobado' || $periodo->estado === 'integrado') {
            return response()->json(['success' => false, 'message' => 'Período ya aprobado.'], 422);
        }

        $detalle = PropinaDetalle::where('periodo_id', $periodoId)->findOrFail($detalleId);

        $validated = $request->validate([
            'dias_laborados'    => 'sometimes|numeric|min:0|max:16',
            'override_dias'     => 'sometimes|boolean',
            'propina_adicional' => 'sometimes|numeric|min:0',
            'sobrante_aplicado' => 'sometimes|numeric|min:0',
            'incluido'          => 'sometimes|boolean',
            'notas'             => 'nullable|string',
        ]);

        // Si cambia días laborados, recalcular propina quincena
        if (isset($validated['dias_laborados'])) {
            $validated['override_dias'] = true;
            $diasLab = (float) $validated['dias_laborados'];
            $propDiaria = (float) $detalle->propina_diaria;
            $validated['propina_quincena'] = round($propDiaria * $diasLab, 2);
        }

        $detalle->update($validated);

        // Recalcular total del detalle
        $detalle->update([
            'total_propina' => round(
                (float) $detalle->fresh()->propina_quincena
                + (float) $detalle->fresh()->sobrante_aplicado
                + (float) $detalle->fresh()->propina_adicional,
                2
            ),
        ]);

        // Recalcular totales del período
        $this->recalcularTotalesPeriodo($periodo);

        return response()->json(['success' => true, 'data' => $detalle->fresh()]);
    }

    // ─── Aprobar período ──────────────────────────────────────────────────────

    public function aprobar(int $id): JsonResponse
    {
        $periodo = PropinaPeriodo::findOrFail($id);

        if ($periodo->estado !== 'calculado') {
            return response()->json(['success' => false, 'message' => 'El período debe estar calculado antes de aprobarse.'], 422);
        }

        $periodo = $this->calculator->aprobarPeriodo($periodo, auth()->id());

        return response()->json(['success' => true, 'data' => $periodo]);
    }

    // ─── Integrar a planilla ──────────────────────────────────────────────────

    public function integrarAPlanilla(Request $request, int $id): JsonResponse
    {
        $periodo = PropinaPeriodo::findOrFail($id);

        if ($periodo->estado !== 'aprobado') {
            return response()->json(['success' => false, 'message' => 'El período debe estar aprobado.'], 422);
        }

        $validated = $request->validate([
            'planilla_id' => 'required|integer',
        ]);

        // Verificar que la planilla existe
        $planilla = DB::connection('rrhh')
            ->table('planillas')
            ->where('id', $validated['planilla_id'])
            ->first();

        if (!$planilla) {
            return response()->json(['success' => false, 'message' => 'Planilla no encontrada.'], 404);
        }

        $integrados = $this->calculator->integrarAPlanilla($periodo, $validated['planilla_id']);

        return response()->json([
            'success' => true,
            'message' => "Propinas integradas a {$integrados} empleado(s) en la planilla.",
            'data'    => $periodo->fresh(),
        ]);
    }

    // ─── Sobrantes ────────────────────────────────────────────────────────────

    public function getSobrantes(Request $request): JsonResponse
    {
        $query = DB::connection('rrhh')
            ->table('propina_sobrantes as s')
            ->orderByDesc('s.created_at');

        if ($request->filled('sucursal_id')) $query->where('s.sucursal_id', $request->sucursal_id);
        if ($request->filled('estado'))      $query->where('s.estado', $request->estado);

        $sobrantes = $query->get();

        $sucIds = $sobrantes->pluck('sucursal_id')->unique();
        $sucs   = DB::connection('pgsql')->table('sucursales')->whereIn('id', $sucIds)->pluck('nombre', 'id');

        $data = $sobrantes->map(fn($s) => (array) $s + [
            'sucursal_nombre' => $sucs[$s->sucursal_id] ?? null,
        ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function rangoQuincena(int $anio, int $mes, int $q): array
    {
        if ($q === 1) {
            $inicio = Carbon::create($anio, $mes, 1);
            $fin    = Carbon::create($anio, $mes, 15);
        } else {
            $inicio = Carbon::create($anio, $mes, 16);
            $fin    = Carbon::create($anio, $mes)->endOfMonth()->startOfDay();
        }
        return [$inicio->toDateString(), $fin->toDateString()];
    }

    private function recalcularTotalesPeriodo(PropinaPeriodo $periodo): void
    {
        $detalles = PropinaDetalle::where('periodo_id', $periodo->id)->where('incluido', true)->get();

        $totalRepartido = $detalles->sum(fn($d) => (float) $d->total_propina);
        $propinaTabla   = (float) $periodo->propina_tabla;
        $recolectada    = (float) $periodo->propina_total_recolectada;
        $retencion      = max(0.0, round($recolectada - $totalRepartido, 2));
        $excedente      = round($totalRepartido - $propinaTabla, 2);

        $periodo->update([
            'propina_repartida' => round($totalRepartido, 2),
            'retencion_monto'   => $retencion,
            'retencion_pct'     => $recolectada > 0 ? round($retencion / $recolectada, 4) : 0,
            'excedente_vs_tabla' => $excedente,
        ]);
    }
}
