<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Desvinculacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DesvinculacionesController extends RRHHBaseController
{
    /**
     * GET /api/rrhh/desvinculaciones
     * Acepta ?tipo=despido|renuncia para filtrar
     */
    public function index(Request $request): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();

        $query = Desvinculacion::with('motivo')
            ->whereIn('empleado_id', $subordinadosIds)
            ->orderByDesc('id');

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        $desvinculaciones = $query->get();
        $data = $this->enrichWithEmpleadoData($desvinculaciones->toArray());

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/rrhh/desvinculaciones
     */
    public function store(Request $request): JsonResponse
    {
        $jefe = $this->getJefeEmpleado();

        $validated = $request->validate([
            'empleado_id'   => 'required|integer',
            'motivo_id'     => 'required|exists:rrhh.motivos_desvinculacion,id',
            'tipo'          => 'required|in:despido,renuncia',
            'fecha_efectiva'=> 'required|date',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        if (!$this->esSubordinado($validated['empleado_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'El empleado no pertenece a tu equipo.',
            ], 403);
        }

        // Capturar datos del empleado para historial denormalizado
        $empData = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('cargos as c', 'e.cargo_id', '=', 'c.id')
            ->join('sucursales as s', 'e.sucursal_id', '=', 's.id')
            ->where('e.id', $validated['empleado_id'])
            ->select('e.nombres', 'e.apellidos', 'c.nombre as cargo', 's.nombre as sucursal')
            ->first();

        $desvinculacion = Desvinculacion::create(array_merge($validated, [
            'procesado_por_id'  => $jefe->id,
            'empleado_nombre'   => $empData ? trim($empData->nombres . ' ' . $empData->apellidos) : null,
            'cargo_nombre'      => $empData?->cargo,
            'sucursal_nombre'   => $empData?->sucursal,
            'aud_usuario'       => Auth::user()->email,
        ]));

        $desvinculacion->load('motivo');

        // Notificar a los administradores de RRHH
        $tipoLabel = $validated['tipo'] === 'despido' ? 'Despido' : 'Renuncia';
        $this->notificarAdminsRrhh(
            tipo:           "Desvinculación — {$tipoLabel}",
            empleadoNombre: $desvinculacion->empleado_nombre ?? "Empleado #{$validated['empleado_id']}",
            detalles: [
                'Tipo'           => $tipoLabel,
                'Fecha efectiva' => $validated['fecha_efectiva'],
                'Motivo'         => $desvinculacion->motivo?->nombre ?? '—',
                'Cargo'          => $desvinculacion->cargo_nombre  ?? '—',
                'Sucursal'       => $desvinculacion->sucursal_nombre ?? '—',
            ],
            rutaFrontend: 'desvinculaciones',
        );

        return response()->json(['success' => true, 'data' => $desvinculacion], 201);
    }

    /**
     * GET /api/rrhh/desvinculaciones/{id}
     */
    public function show(int $id): JsonResponse
    {
        $desvinculacion = Desvinculacion::with('motivo')->findOrFail($id);
        $arr = $this->enrichWithEmpleadoData([$desvinculacion->toArray()]);

        return response()->json(['success' => true, 'data' => $arr[0]]);
    }

    /**
     * PUT /api/rrhh/desvinculaciones/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $desvinculacion = Desvinculacion::findOrFail($id);

        $validated = $request->validate([
            'motivo_id'     => 'sometimes|exists:rrhh.motivos_desvinculacion,id',
            'fecha_efectiva'=> 'sometimes|date',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        $desvinculacion->update(array_merge($validated, ['aud_usuario' => Auth::user()->email]));
        $desvinculacion->load('motivo');

        return response()->json(['success' => true, 'data' => $desvinculacion]);
    }

    /**
     * DELETE /api/rrhh/desvinculaciones/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        Desvinculacion::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Desvinculación eliminada.']);
    }
}
