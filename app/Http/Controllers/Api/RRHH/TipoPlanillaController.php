<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\TipoPlanilla;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TipoPlanillaController extends Controller
{
    /** GET /api/rrhh/tipo-planilla — catálogo completo */
    public function index(): JsonResponse
    {
        $tipos = TipoPlanilla::where('activo', true)->orderBy('id')->get();
        return response()->json(['success' => true, 'data' => $tipos]);
    }

    /**
     * GET /api/rrhh/tipo-planilla/empleados
     * Todos los empleados activos con sus tipos de planilla asignados (con fechas).
     */
    public function empleados(): JsonResponse
    {
        $empleados = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('sucursales as s', 's.id', '=', 'e.sucursal_id')
            ->where('e.activo', true)
            ->orderBy('e.apellidos')
            ->select('e.id', 'e.codigo', 'e.nombres', 'e.apellidos', 's.nombre as sucursal')
            ->get();

        // Mapa empleado_id → tipos (con fechas)
        $asignaciones = DB::table('empleado_tipo_planilla as etp')
            ->join('tipo_planilla as tp', 'tp.id', '=', 'etp.tipo_planilla_id')
            ->select('etp.empleado_id', 'tp.id', 'tp.slug', 'tp.nombre', 'etp.fecha_inicio', 'etp.fecha_fin')
            ->get()
            ->groupBy('empleado_id');

        $data = $empleados->map(function ($emp) use ($asignaciones) {
            $tipos = collect($asignaciones->get($emp->id, []))
                ->map(fn($t) => [
                    'id'          => $t->id,
                    'slug'        => $t->slug,
                    'nombre'      => $t->nombre,
                    'fecha_inicio'=> $t->fecha_inicio,
                    'fecha_fin'   => $t->fecha_fin,
                ])
                ->values();
            return [
                'id'       => $emp->id,
                'codigo'   => $emp->codigo,
                'nombres'  => $emp->nombres,
                'apellidos'=> $emp->apellidos,
                'sucursal' => $emp->sucursal,
                'tipos'    => $tipos,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** GET /api/rrhh/empleados/{id}/tipo-planilla — tipos asignados al empleado (con fechas) */
    public function getEmpleado(int $empleadoId): JsonResponse
    {
        $asignados = DB::table('empleado_tipo_planilla as etp')
            ->join('tipo_planilla as tp', 'tp.id', '=', 'etp.tipo_planilla_id')
            ->where('etp.empleado_id', $empleadoId)
            ->select('tp.id', 'tp.slug', 'tp.nombre', 'tp.descripcion', 'etp.fecha_inicio', 'etp.fecha_fin')
            ->orderBy('tp.id')
            ->get();

        return response()->json(['success' => true, 'data' => $asignados]);
    }

    /**
     * PUT /api/rrhh/empleados/{id}/tipo-planilla
     * Body: { tipos: [{tipo_planilla_id, fecha_inicio, fecha_fin?}] }
     * Reemplaza los tipos asignados (sync). Debe tener al menos 1.
     */
    public function syncEmpleado(int $empleadoId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipos'                     => 'required|array|min:1',
            'tipos.*.tipo_planilla_id'  => 'required|integer|exists:tipo_planilla,id',
            'tipos.*.fecha_inicio'      => 'required|date',
            'tipos.*.fecha_fin'         => 'nullable|date|after_or_equal:tipos.*.fecha_inicio',
        ]);

        // Eliminar duplicados por tipo_planilla_id (quedarse con el último)
        $tiposMapa = [];
        foreach ($validated['tipos'] as $t) {
            $tiposMapa[$t['tipo_planilla_id']] = $t;
        }

        DB::transaction(function () use ($empleadoId, $tiposMapa) {
            DB::table('empleado_tipo_planilla')->where('empleado_id', $empleadoId)->delete();
            DB::table('empleado_tipo_planilla')->insert(
                array_map(fn($t) => [
                    'empleado_id'      => $empleadoId,
                    'tipo_planilla_id' => $t['tipo_planilla_id'],
                    'fecha_inicio'     => $t['fecha_inicio'],
                    'fecha_fin'        => $t['fecha_fin'] ?? null,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ], array_values($tiposMapa))
            );
        });

        return $this->getEmpleado($empleadoId);
    }
}
