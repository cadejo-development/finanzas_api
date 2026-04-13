<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DepartamentosController extends Controller
{
    /**
     * Retorna el árbol de departamentos con jefe, empleados y sucursal.
     * GET /api/rrhh/admin/departamentos
     */
    public function index(): JsonResponse
    {
        $depts = DB::connection('pgsql')
            ->table('departamentos as d')
            ->leftJoin('sucursales as s', 'd.sucursal_id', '=', 's.id')
            ->where('d.activo', true)
            ->select('d.id', 'd.codigo', 'd.nombre', 'd.descripcion', 'd.parent_id',
                     'd.sucursal_id', 'd.jefe_empleado_id', 'd.activo',
                     's.nombre as sucursal_nombre')
            ->orderBy('d.nombre')
            ->get()
            ->toArray();

        // Jefes
        $jefeIds = collect($depts)->pluck('jefe_empleado_id')->filter()->unique()->values()->all();
        $jefes = [];
        if (!empty($jefeIds)) {
            $jefes = DB::connection('pgsql')
                ->table('empleados')
                ->whereIn('id', $jefeIds)
                ->select('id', 'nombres', 'apellidos', 'codigo')
                ->get()->keyBy('id')->toArray();
        }

        // Conteo de empleados por departamento
        $deptIds = collect($depts)->pluck('id')->all();
        $counts = [];
        $preview = [];
        if (!empty($deptIds)) {
            $counts = DB::connection('pgsql')
                ->table('empleados')
                ->whereIn('departamento_id', $deptIds)
                ->where('activo', true)
                ->groupBy('departamento_id')
                ->selectRaw('departamento_id, COUNT(*) as total')
                ->get()->keyBy('departamento_id')->toArray();

            // Preview: primeros 4 empleados por departamento (excluye al jefe para no duplicar)
            $allEmps = DB::connection('pgsql')
                ->table('empleados')
                ->whereIn('departamento_id', $deptIds)
                ->where('activo', true)
                ->select('id', 'nombres', 'apellidos', 'cargo', 'departamento_id')
                ->orderBy('nombres')
                ->get();

            foreach ($allEmps as $emp) {
                $did = $emp->departamento_id;
                if (!isset($preview[$did])) $preview[$did] = [];
                if (count($preview[$did]) < 4) {
                    $preview[$did][] = [
                        'id'     => $emp->id,
                        'nombre' => trim($emp->nombres . ' ' . $emp->apellidos),
                        'cargo'  => $emp->cargo ?? '',
                        'inicial'=> mb_strtoupper(mb_substr(trim($emp->nombres), 0, 1)),
                    ];
                }
            }
        }

        // Enriquecer con jefe y conteo
        $depts = collect($depts)->map(function ($d) use ($jefes, $counts, $preview) {
            $d = (array) $d;
            $jefe = $d['jefe_empleado_id'] ? ($jefes[$d['jefe_empleado_id']] ?? null) : null;
            $d['jefe_nombre'] = $jefe
                ? trim(($jefe instanceof \stdClass ? $jefe->nombres : $jefe['nombres']) . ' '
                     . ($jefe instanceof \stdClass ? $jefe->apellidos : $jefe['apellidos']))
                : null;
            $d['jefe_codigo'] = $jefe
                ? ($jefe instanceof \stdClass ? $jefe->codigo : $jefe['codigo'])
                : null;
            $countEntry = $counts[$d['id']] ?? null;
            $d['total_empleados'] = $countEntry
                ? (int) ($countEntry instanceof \stdClass ? $countEntry->total : $countEntry['total'])
                : 0;
            $d['empleados_preview'] = $preview[$d['id']] ?? [];
            $d['children'] = [];
            return $d;
        })->all();

        return response()->json([
            'success' => true,
            'data'    => $this->buildTree($depts),
        ]);
    }

    /**
     * Crear departamento.
     * POST /api/rrhh/admin/departamentos
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo'           => 'required|string|max:30|unique:pgsql.departamentos,codigo',
            'nombre'           => 'required|string|max:150',
            'descripcion'      => 'nullable|string',
            'parent_id'        => 'nullable|integer|exists:pgsql.departamentos,id',
            'sucursal_id'      => 'nullable|integer|exists:pgsql.sucursales,id',
            'jefe_empleado_id' => 'nullable|integer|exists:pgsql.empleados,id',
        ]);

        $id = DB::connection('pgsql')->table('departamentos')->insertGetId(array_merge($validated, [
            'activo'      => true,
            'aud_usuario' => Auth::user()->email,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]));

        return response()->json(['success' => true, 'data' => ['id' => $id] + $validated], 201);
    }

    /**
     * Actualizar departamento.
     * PUT /api/rrhh/admin/departamentos/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'codigo'           => 'sometimes|string|max:30|unique:pgsql.departamentos,codigo,' . $id,
            'nombre'           => 'sometimes|string|max:150',
            'descripcion'      => 'nullable|string',
            'parent_id'        => 'nullable|integer|exists:pgsql.departamentos,id',
            'sucursal_id'      => 'nullable|integer|exists:pgsql.sucursales,id',
            'jefe_empleado_id' => 'nullable|integer|exists:pgsql.empleados,id',
        ]);

        DB::connection('pgsql')->table('departamentos')->where('id', $id)->update(
            array_merge($validated, ['aud_usuario' => Auth::user()->email, 'updated_at' => now()])
        );

        return response()->json(['success' => true, 'message' => 'Departamento actualizado.']);
    }

    /**
     * Eliminar departamento (soft-delete via activo=false).
     * DELETE /api/rrhh/admin/departamentos/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        DB::connection('pgsql')->table('departamentos')->where('id', $id)->update([
            'activo'     => false,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Departamento eliminado.']);
    }

    /**
     * Empleados del departamento (con flag de membresía).
     * GET /api/rrhh/admin/departamentos/{id}/empleados
     */
    public function empleados(int $id): JsonResponse
    {
        $miembros = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('cargos as c', 'e.cargo_id', '=', 'c.id')
            ->join('sucursales as s', 'e.sucursal_id', '=', 's.id')
            ->where('e.departamento_id', $id)
            ->where('e.activo', true)
            ->select('e.id', 'e.codigo', 'e.nombres', 'e.apellidos',
                     'c.nombre as cargo', 's.nombre as sucursal')
            ->orderBy('e.apellidos')
            ->get();

        return response()->json(['success' => true, 'data' => $miembros]);
    }

    /**
     * Todos los empleados activos (para selector al asignar).
     * GET /api/rrhh/admin/empleados
     */
    public function todosEmpleados(): JsonResponse
    {
        $empleados = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('cargos as c', 'e.cargo_id', '=', 'c.id')
            ->join('sucursales as s', 'e.sucursal_id', '=', 's.id')
            ->leftJoin('departamentos as d', 'e.departamento_id', '=', 'd.id')
            ->where('e.activo', true)
            ->select('e.id', 'e.codigo', 'e.nombres', 'e.apellidos',
                     'c.nombre as cargo', 's.nombre as sucursal',
                     'e.departamento_id', 'd.nombre as departamento_nombre')
            ->orderBy('e.apellidos')
            ->get();

        return response()->json(['success' => true, 'data' => $empleados]);
    }

    /**
     * Asignar empleado a departamento.
     * POST /api/rrhh/admin/departamentos/{id}/empleados/{empId}
     */
    public function asignarEmpleado(int $id, int $empId): JsonResponse
    {
        DB::connection('pgsql')->table('empleados')->where('id', $empId)->update([
            'departamento_id' => $id,
            'updated_at'      => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Empleado asignado al departamento.']);
    }

    /**
     * Quitar empleado del departamento.
     * DELETE /api/rrhh/admin/departamentos/{id}/empleados/{empId}
     */
    public function quitarEmpleado(int $id, int $empId): JsonResponse
    {
        DB::connection('pgsql')->table('empleados')
            ->where('id', $empId)
            ->where('departamento_id', $id)
            ->update(['departamento_id' => null, 'updated_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Empleado removido del departamento.']);
    }

    /**
     * Asignar jefe al departamento.
     * PATCH /api/rrhh/admin/departamentos/{id}/jefe/{empId}
     */
    public function asignarJefe(int $id, int $empId): JsonResponse
    {
        DB::connection('pgsql')->table('departamentos')->where('id', $id)->update([
            'jefe_empleado_id' => $empId,
            'updated_at'       => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Jefe asignado al departamento.']);
    }

    /**
     * Quitar jefe del departamento.
     * DELETE /api/rrhh/admin/departamentos/{id}/jefe
     */
    public function quitarJefe(int $id): JsonResponse
    {
        DB::connection('pgsql')->table('departamentos')->where('id', $id)->update([
            'jefe_empleado_id' => null,
            'updated_at'       => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Jefe removido.']);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function buildTree(array $depts, ?int $parentId = null): array
    {
        return collect($depts)
            ->filter(fn($d) => $d['parent_id'] === $parentId)
            ->map(function ($d) use ($depts) {
                $d['children'] = $this->buildTree($depts, $d['id']);
                return $d;
            })
            ->values()
            ->all();
    }
}
