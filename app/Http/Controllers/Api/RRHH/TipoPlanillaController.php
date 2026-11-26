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

    /** GET /api/rrhh/empleados/{id}/tipo-planilla — tipos asignados al empleado */
    public function getEmpleado(int $empleadoId): JsonResponse
    {
        $asignados = DB::table('empleado_tipo_planilla as etp')
            ->join('tipo_planilla as tp', 'tp.id', '=', 'etp.tipo_planilla_id')
            ->where('etp.empleado_id', $empleadoId)
            ->select('tp.id', 'tp.slug', 'tp.nombre', 'tp.descripcion')
            ->orderBy('tp.id')
            ->get();

        return response()->json(['success' => true, 'data' => $asignados]);
    }

    /**
     * PUT /api/rrhh/empleados/{id}/tipo-planilla
     * Body: { tipo_planilla_ids: [1, 2] }
     * Reemplaza los tipos asignados (sync). Debe tener al menos 1.
     */
    public function syncEmpleado(int $empleadoId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipo_planilla_ids'   => 'required|array|min:1',
            'tipo_planilla_ids.*' => 'integer|exists:tipo_planilla,id',
        ]);

        $ids = array_unique($validated['tipo_planilla_ids']);

        DB::transaction(function () use ($empleadoId, $ids) {
            DB::table('empleado_tipo_planilla')->where('empleado_id', $empleadoId)->delete();
            DB::table('empleado_tipo_planilla')->insert(
                array_map(fn($tid) => [
                    'empleado_id'      => $empleadoId,
                    'tipo_planilla_id' => $tid,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ], $ids)
            );
        });

        return $this->getEmpleado($empleadoId);
    }
}
