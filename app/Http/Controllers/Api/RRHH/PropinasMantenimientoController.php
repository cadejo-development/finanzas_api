<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\PropinaAdicionalConfig;
use App\Models\RRHH\PropinaConfigSucursal;
use App\Models\RRHH\PropinaPuntosEmpleado;
use App\Models\RRHH\PropinaPuntosCargo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PropinasMantenimientoController extends RRHHBaseController
{
    // ─── Configuración por sucursal ──────────────────────────────────────────

    public function getConfigSucursales(): JsonResponse
    {
        $configs = PropinaConfigSucursal::orderBy('sucursal_id')->get();

        // Enriquecer con nombre de sucursal
        $sucursales = DB::connection('pgsql')
            ->table('sucursales')
            ->whereIn('id', $configs->pluck('sucursal_id'))
            ->pluck('nombre', 'id');

        $data = $configs->map(fn($c) => array_merge($c->toArray(), [
            'sucursal_nombre' => $sucursales[$c->sucursal_id] ?? null,
        ]));

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function updateConfigSucursal(Request $request, int $id): JsonResponse
    {
        $config = PropinaConfigSucursal::findOrFail($id);

        $validated = $request->validate([
            'nombre_grupo'               => 'nullable|string|max:100',
            'pct_propina_sobre_venta'    => 'required|numeric|min:0|max:1',
            'pct_distribucion_empleados' => 'required|numeric|min:0|max:1',
            'dias_quincena_1'            => 'required|integer|min:1|max:16',
            'dias_quincena_2'            => 'required|integer|min:1|max:16',
            'activa'                     => 'boolean',
            'notas'                      => 'nullable|string',
        ]);

        $config->update($validated);
        return response()->json(['success' => true, 'data' => $config]);
    }

    public function storeConfigSucursal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sucursal_id'                => 'required|integer',
            'nombre_grupo'               => 'nullable|string|max:100',
            'pct_propina_sobre_venta'    => 'required|numeric|min:0|max:1',
            'pct_distribucion_empleados' => 'required|numeric|min:0|max:1',
            'dias_quincena_1'            => 'required|integer|min:1|max:16',
            'dias_quincena_2'            => 'required|integer|min:1|max:16',
            'activa'                     => 'boolean',
        ]);

        $config = PropinaConfigSucursal::create($validated);
        return response()->json(['success' => true, 'data' => $config], 201);
    }

    // ─── Puntos por cargo ────────────────────────────────────────────────────

    public function getPuntosCargo(): JsonResponse
    {
        $puntos = PropinaPuntosCargo::orderBy('cargo_id')->get();

        $cargos = DB::connection('pgsql')
            ->table('cargos')
            ->pluck('nombre', 'id');

        // Traer también cargos sin configuración para mostrar en UI
        $todosLosCargos = DB::connection('pgsql')
            ->table('cargos')
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        $puntosMap = $puntos->keyBy('cargo_id');

        $data = $todosLosCargos->map(function ($c) use ($puntosMap) {
            $cfg = $puntosMap->get($c->id);
            return [
                'cargo_id'       => $c->id,
                'cargo_nombre'   => $c->nombre,
                'puntos_propina' => $cfg ? (float) $cfg->puntos_propina : null,
                'config_id'      => $cfg?->id,
                'notas'          => $cfg?->notas,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function upsertPuntosCargo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cargo_id'       => 'required|integer',
            'puntos_propina' => 'required|numeric|min:0|max:5',
            'notas'          => 'nullable|string',
        ]);

        $config = PropinaPuntosCargo::updateOrCreate(
            ['cargo_id' => $validated['cargo_id']],
            ['puntos_propina' => $validated['puntos_propina'], 'notas' => $validated['notas'] ?? null]
        );

        return response()->json(['success' => true, 'data' => $config]);
    }

    // ─── Puntos por empleado (overrides) ─────────────────────────────────────

    public function getPuntosEmpleado(Request $request): JsonResponse
    {
        $query = PropinaPuntosEmpleado::orderByDesc('fecha_desde');

        if ($request->filled('empleado_id')) {
            $query->where('empleado_id', $request->empleado_id);
        }

        $registros = $query->get();

        $empIds = $registros->pluck('empleado_id')->unique();
        $empleados = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('cargos as c', 'c.id', '=', 'e.cargo_id')
            ->whereIn('e.id', $empIds)
            ->select('e.id', 'e.nombres', 'e.apellidos', 'c.nombre as cargo_nombre')
            ->get()->keyBy('id');

        $data = $registros->map(fn($r) => array_merge($r->toArray(), [
            'empleado_nombre' => isset($empleados[$r->empleado_id])
                ? trim($empleados[$r->empleado_id]->nombres . ' ' . $empleados[$r->empleado_id]->apellidos)
                : null,
            'cargo_nombre' => $empleados[$r->empleado_id]?->cargo_nombre,
        ]));

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function storePuntosEmpleado(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'empleado_id'    => 'required|integer',
            'puntos_propina' => 'required|numeric|min:0|max:5',
            'fecha_desde'    => 'required|date',
            'fecha_hasta'    => 'nullable|date|after_or_equal:fecha_desde',
            'motivo'         => 'nullable|string|max:300',
        ]);

        $validated['registrado_por'] = auth()->id();

        $registro = PropinaPuntosEmpleado::create($validated);
        return response()->json(['success' => true, 'data' => $registro], 201);
    }

    public function updatePuntosEmpleado(Request $request, int $id): JsonResponse
    {
        $registro = PropinaPuntosEmpleado::findOrFail($id);

        $validated = $request->validate([
            'puntos_propina' => 'required|numeric|min:0|max:5',
            'fecha_desde'    => 'required|date',
            'fecha_hasta'    => 'nullable|date|after_or_equal:fecha_desde',
            'motivo'         => 'nullable|string|max:300',
        ]);

        $registro->update($validated);
        return response()->json(['success' => true, 'data' => $registro]);
    }

    public function destroyPuntosEmpleado(int $id): JsonResponse
    {
        PropinaPuntosEmpleado::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    // ─── Propina adicional por cargo/sucursal ─────────────────────────────────

    public function getAdicionales(): JsonResponse
    {
        $configs = PropinaAdicionalConfig::orderBy('sucursal_id')->orderBy('cargo_id')->get();

        $cargoIds   = $configs->pluck('cargo_id')->filter()->unique();
        $sucIds     = $configs->pluck('sucursal_id')->filter()->unique();

        $cargos = DB::connection('pgsql')->table('cargos')->whereIn('id', $cargoIds)->pluck('nombre', 'id');
        $sucs   = DB::connection('pgsql')->table('sucursales')->whereIn('id', $sucIds)->pluck('nombre', 'id');

        $data = $configs->map(fn($c) => array_merge($c->toArray(), [
            'cargo_nombre'    => $c->cargo_id    ? ($cargos[$c->cargo_id] ?? null)    : 'Todos',
            'sucursal_nombre' => $c->sucursal_id ? ($sucs[$c->sucursal_id] ?? null)   : 'Todas',
        ]));

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function storeAdicional(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sucursal_id' => 'nullable|integer',
            'cargo_id'    => 'nullable|integer',
            'monto'       => 'required|numeric|min:0',
            'descripcion' => 'nullable|string|max:200',
            'activa'      => 'boolean',
        ]);

        $config = PropinaAdicionalConfig::create($validated);
        return response()->json(['success' => true, 'data' => $config], 201);
    }

    public function updateAdicional(Request $request, int $id): JsonResponse
    {
        $config = PropinaAdicionalConfig::findOrFail($id);

        $validated = $request->validate([
            'sucursal_id' => 'nullable|integer',
            'cargo_id'    => 'nullable|integer',
            'monto'       => 'required|numeric|min:0',
            'descripcion' => 'nullable|string|max:200',
            'activa'      => 'boolean',
        ]);

        $config->update($validated);
        return response()->json(['success' => true, 'data' => $config]);
    }

    public function destroyAdicional(int $id): JsonResponse
    {
        PropinaAdicionalConfig::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    // ─── Flujos entre sucursales ──────────────────────────────────────────────

    public function getFlujos(): JsonResponse
    {
        $flujos = DB::connection('rrhh')->table('propina_flujos_sucursal')->get();

        $sucIds = $flujos->flatMap(fn($f) => [$f->sucursal_origen_id, $f->sucursal_destino_id])->unique();
        $sucs   = DB::connection('pgsql')->table('sucursales')->whereIn('id', $sucIds)->pluck('nombre', 'id');

        $data = $flujos->map(fn($f) => [
            'id'                   => $f->id,
            'sucursal_origen_id'   => $f->sucursal_origen_id,
            'sucursal_origen'      => $sucs[$f->sucursal_origen_id] ?? null,
            'sucursal_destino_id'  => $f->sucursal_destino_id,
            'sucursal_destino'     => $sucs[$f->sucursal_destino_id] ?? null,
            'activo'               => $f->activo,
        ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function storeFlujo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sucursal_origen_id'  => 'required|integer',
            'sucursal_destino_id' => 'required|integer|different:sucursal_origen_id',
            'activo'              => 'boolean',
        ]);

        $id = DB::connection('rrhh')->table('propina_flujos_sucursal')->insertGetId(
            array_merge($validated, ['created_at' => now(), 'updated_at' => now()])
        );

        return response()->json(['success' => true, 'data' => ['id' => $id] + $validated], 201);
    }

    public function destroyFlujo(int $id): JsonResponse
    {
        DB::connection('rrhh')->table('propina_flujos_sucursal')->where('id', $id)->delete();
        return response()->json(['success' => true]);
    }
}
