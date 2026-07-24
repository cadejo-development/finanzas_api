<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\KpiPlantilla;
use App\Models\KpiEscalaBonificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KpiPlantillasController extends Controller
{
    public function index(): JsonResponse
    {
        $plantillas = KpiPlantilla::with([
                'sucursal:id,nombre',
                'departamento:id,nombre',
                'cargos:id,nombre,codigo',
                'escala',
            ])
            ->withCount('cargos')
            ->orderBy('nombre')
            ->get()
            ->map(fn($p) => $this->format($p));

        return response()->json($plantillas);
    }

    public function show(int $id): JsonResponse
    {
        $plantilla = KpiPlantilla::with([
                'sucursal:id,nombre',
                'departamento:id,nombre',
                'cargos:id,nombre,codigo',
                'escala',
            ])->findOrFail($id);

        return response()->json($this->format($plantilla));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre'          => 'required|string|max:150',
            'descripcion'     => 'nullable|string|max:1000',
            'sucursal_id'     => 'nullable|integer|exists:pgsql.sucursales,id',
            'departamento_id' => 'nullable|integer|exists:pgsql.departamentos,id',
            'unidad_medida'   => 'required|string|max:80',
            'monto_objetivo'  => 'nullable|string|max:50',
            'monto_bono_base' => 'nullable|numeric|min:0',
            'tipo_meta'       => 'nullable|in:fijo,variable',
            'activo'          => 'boolean',
            'cargo_ids'       => 'array',
            'cargo_ids.*'     => 'integer|exists:pgsql.cargos,id',
            'escala'          => 'array',
            'escala.*.porcentaje_desde' => 'required|numeric|min:0|max:100',
            'escala.*.operador'         => 'nullable|in:>=,>,<=,<',
            'escala.*.tipo'             => 'required|in:porcentaje_bono,monto_fijo',
            'escala.*.valor'            => 'required|numeric|min:0',
        ]);

        DB::connection('pgsql')->beginTransaction();
        try {
            $plantilla = KpiPlantilla::create([
                'nombre'          => $data['nombre'],
                'descripcion'     => $data['descripcion'] ?? null,
                'sucursal_id'     => $data['sucursal_id'] ?? null,
                'departamento_id' => $data['departamento_id'] ?? null,
                'unidad_medida'   => $data['unidad_medida'],
                'monto_objetivo'  => $data['monto_objetivo'] ?? null,
                'monto_bono_base' => isset($data['monto_bono_base']) ? (float) $data['monto_bono_base'] : null,
                'tipo_meta'       => $data['tipo_meta'] ?? 'fijo',
                'activo'          => $data['activo'] ?? true,
                'aud_usuario'     => $request->user()?->email ?? 'sistema',
            ]);

            if (!empty($data['cargo_ids'])) {
                $plantilla->cargos()->sync($data['cargo_ids']);
            }

            foreach (($data['escala'] ?? []) as $i => $fila) {
                KpiEscalaBonificacion::create([
                    'kpi_plantilla_id' => $plantilla->id,
                    'porcentaje_desde' => $fila['porcentaje_desde'],
                    'operador'         => $fila['operador'] ?? '>=',
                    'tipo'             => $fila['tipo'],
                    'valor'            => $fila['valor'],
                    'orden'            => $i,
                ]);
            }

            DB::connection('pgsql')->commit();
        } catch (\Throwable $e) {
            DB::connection('pgsql')->rollBack();
            throw $e;
        }

        $plantilla->load(['sucursal:id,nombre', 'cargos:id,nombre,codigo', 'escala']);
        $plantilla->loadCount('cargos');

        return response()->json($this->format($plantilla), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $plantilla = KpiPlantilla::findOrFail($id);

        $data = $request->validate([
            'nombre'          => 'required|string|max:150',
            'descripcion'     => 'nullable|string|max:1000',
            'sucursal_id'     => 'nullable|integer|exists:pgsql.sucursales,id',
            'departamento_id' => 'nullable|integer|exists:pgsql.departamentos,id',
            'unidad_medida'   => 'required|string|max:80',
            'monto_objetivo'  => 'nullable|string|max:50',
            'monto_bono_base' => 'nullable|numeric|min:0',
            'tipo_meta'       => 'nullable|in:fijo,variable',
            'activo'          => 'boolean',
            'cargo_ids'       => 'array',
            'cargo_ids.*'     => 'integer|exists:pgsql.cargos,id',
            'escala'          => 'array',
            'escala.*.porcentaje_desde' => 'required|numeric|min:0|max:100',
            'escala.*.operador'         => 'nullable|in:>=,>,<=,<',
            'escala.*.tipo'             => 'required|in:porcentaje_bono,monto_fijo',
            'escala.*.valor'            => 'required|numeric|min:0',
        ]);

        DB::connection('pgsql')->beginTransaction();
        try {
            $plantilla->update([
                'nombre'          => $data['nombre'],
                'descripcion'     => $data['descripcion'] ?? null,
                'sucursal_id'     => $data['sucursal_id'] ?? null,
                'departamento_id' => $data['departamento_id'] ?? null,
                'unidad_medida'   => $data['unidad_medida'],
                'monto_objetivo'  => $data['monto_objetivo'] ?? null,
                'monto_bono_base' => isset($data['monto_bono_base']) ? (float) $data['monto_bono_base'] : null,
                'tipo_meta'       => $data['tipo_meta'] ?? $plantilla->tipo_meta,
                'activo'          => $data['activo'] ?? $plantilla->activo,
                'aud_usuario'     => $request->user()?->email ?? 'sistema',
            ]);

            $plantilla->cargos()->sync($data['cargo_ids'] ?? []);

            $plantilla->escala()->delete();
            foreach (($data['escala'] ?? []) as $i => $fila) {
                KpiEscalaBonificacion::create([
                    'kpi_plantilla_id' => $plantilla->id,
                    'porcentaje_desde' => $fila['porcentaje_desde'],
                    'operador'         => $fila['operador'] ?? '>=',
                    'tipo'             => $fila['tipo'],
                    'valor'            => $fila['valor'],
                    'orden'            => $i,
                ]);
            }

            DB::connection('pgsql')->commit();
        } catch (\Throwable $e) {
            DB::connection('pgsql')->rollBack();
            throw $e;
        }

        $plantilla->load(['sucursal:id,nombre', 'cargos:id,nombre,codigo', 'escala']);
        $plantilla->loadCount('cargos');

        return response()->json($this->format($plantilla));
    }

    public function toggleActivo(int $id): JsonResponse
    {
        $plantilla = KpiPlantilla::findOrFail($id);
        $plantilla->update(['activo' => !$plantilla->activo]);
        $plantilla->load(['sucursal:id,nombre', 'departamento:id,nombre', 'cargos:id,nombre,codigo', 'escala']);
        $plantilla->loadCount('cargos');

        return response()->json($this->format($plantilla));
    }

    public function destroy(int $id): JsonResponse
    {
        KpiPlantilla::findOrFail($id)->delete();
        return response()->json(['message' => 'Plantilla de KPI eliminada.']);
    }

    public function empleadosAfectados(int $id): JsonResponse
    {
        $plantilla = KpiPlantilla::with('cargos:id')->findOrFail($id);
        $cargoIds  = $plantilla->cargos->pluck('id')->toArray();

        if (empty($cargoIds)) {
            return response()->json([]);
        }

        $query = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('cargos as c', 'e.cargo_id', '=', 'c.id')
            ->leftJoin('sucursales as s', 'e.sucursal_id', '=', 's.id')
            ->where('e.activo', true)
            ->whereIn('e.cargo_id', $cargoIds)
            ->orderBy('c.nombre')
            ->orderByRaw("TRIM(e.nombres || ' ' || e.apellidos)")
            ->selectRaw("e.id, TRIM(e.nombres || ' ' || e.apellidos) as nombre, c.nombre as cargo, COALESCE(s.nombre, 'Sin sucursal') as sucursal");

        if ($plantilla->sucursal_id) {
            $query->where('e.sucursal_id', $plantilla->sucursal_id);
        }
        if ($plantilla->departamento_id) {
            $query->where('e.departamento_id', $plantilla->departamento_id);
        }

        return response()->json($query->get());
    }

    public function cargosDisponibles(Request $request): JsonResponse
    {
        $sucursalId     = $request->input('sucursal_id');
        $departamentoId = $request->input('departamento_id');

        // Subquery para contar empleados activos de este cargo en el scope seleccionado
        $countWhere  = 'e2.cargo_id = c.id AND e2.activo = true';
        $countBindings = [];
        if ($sucursalId) {
            $countWhere .= ' AND e2.sucursal_id = ?';
            $countBindings[] = (int) $sucursalId;
        }
        if ($departamentoId) {
            $countWhere .= ' AND e2.departamento_id = ?';
            $countBindings[] = (int) $departamentoId;
        }

        $query = \Illuminate\Support\Facades\DB::connection('pgsql')
            ->table('cargos as c')
            ->where('c.activo', true)
            ->orderBy('c.nombre')
            ->selectRaw(
                'c.id, c.nombre, c.codigo, (SELECT COUNT(*) FROM empleados e2 WHERE ' . $countWhere . ') as total_empleados',
                $countBindings
            );

        if ($sucursalId || $departamentoId) {
            $query->where(function ($outer) use ($sucursalId, $departamentoId) {
                // Empleados regulares que trabajan en la sucursal/departamento
                $outer->whereExists(function ($sub) use ($sucursalId, $departamentoId) {
                    $sub->from('empleados as e')
                        ->whereColumn('e.cargo_id', 'c.id')
                        ->where('e.activo', true);

                    if ($sucursalId) {
                        $sub->where('e.sucursal_id', (int) $sucursalId);
                    }
                    if ($departamentoId) {
                        $sub->where('e.departamento_id', (int) $departamentoId);
                    }
                });

                // Jefes/gerentes vinculados al departamento vía departamentos.jefe_empleado_id
                $outer->orWhereExists(function ($sub) use ($sucursalId, $departamentoId) {
                    $sub->from('empleados as ej')
                        ->join('departamentos as d', 'd.jefe_empleado_id', '=', 'ej.id')
                        ->whereColumn('ej.cargo_id', 'c.id')
                        ->where('ej.activo', true)
                        ->where('d.activo', true);

                    if ($sucursalId) {
                        $sub->where('d.sucursal_id', (int) $sucursalId);
                    }
                    if ($departamentoId) {
                        $sub->where('d.id', (int) $departamentoId);
                    }
                });
            });
        }

        return response()->json($query->distinct()->get());
    }

    private function format(KpiPlantilla $p): array
    {
        return [
            'id'                  => $p->id,
            'nombre'              => $p->nombre,
            'descripcion'         => $p->descripcion,
            'sucursal_id'         => $p->sucursal_id,
            'sucursal_nombre'     => $p->sucursal?->nombre,
            'departamento_id'     => $p->departamento_id,
            'departamento_nombre' => $p->departamento?->nombre,
            'unidad_medida'       => $p->unidad_medida,
            'monto_objetivo'      => $p->monto_objetivo,
            'monto_bono_base'     => $p->monto_bono_base,
            'tipo_meta'           => $p->tipo_meta ?? 'fijo',
            'activo'         => $p->activo,
            'cargos_count'   => $p->cargos_count ?? $p->cargos->count(),
            'cargos'         => $p->cargos->map(fn($c) => [
                                    'id'     => $c->id,
                                    'nombre' => $c->nombre,
                                    'codigo' => $c->codigo,
                                ]),
            'escala'         => $p->escala->map(fn($e) => [
                                    'id'                => $e->id,
                                    'porcentaje_desde'  => $e->porcentaje_desde,
                                    'operador'          => $e->operador ?? '>=',
                                    'tipo'              => $e->tipo,
                                    'valor'             => $e->valor,
                                    'orden'             => $e->orden,
                                ]),
        ];
    }
}
