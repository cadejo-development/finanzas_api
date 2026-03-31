<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Amonestacion;
use App\Models\RRHH\DiaSuspension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AmonestacionesController extends RRHHBaseController
{
    /**
     * GET /api/rrhh/amonestaciones
     */
    public function index(): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();

        $amonestaciones = Amonestacion::with(['tipoFalta', 'diasSuspension'])
            ->whereIn('empleado_id', $subordinadosIds)
            ->orderByDesc('id')
            ->get();

        $data = $this->enrichWithEmpleadoData($amonestaciones->toArray());

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/rrhh/amonestaciones
     */
    public function store(Request $request): JsonResponse
    {
        $jefe = $this->getJefeEmpleado();

        $validated = $request->validate([
            'empleado_id'        => 'required|integer',
            'tipo_falta_id'      => 'required|exists:rrhh.tipos_falta,id',
            'fecha_amonestacion' => 'required|date',
            'descripcion'        => 'required|string|max:1000',
            'accion_tomada'      => 'nullable|string|max:500',
            'aplica_suspension'  => 'boolean',
            'dias_suspension'    => 'nullable|array|required_if:aplica_suspension,true',
            'dias_suspension.*'  => 'date',
        ]);

        if (!$this->puedeGestionar($validated['empleado_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'El empleado no pertenece a tu equipo.',
            ], 403);
        }

        $aplica = $validated['aplica_suspension'] ?? false;

        $amonestacion = Amonestacion::create([
            'empleado_id'        => $validated['empleado_id'],
            'jefe_id'            => $jefe->id,
            'tipo_falta_id'      => $validated['tipo_falta_id'],
            'fecha_amonestacion' => $validated['fecha_amonestacion'],
            'descripcion'        => $validated['descripcion'],
            'accion_tomada'      => $validated['accion_tomada'] ?? null,
            'aplica_suspension'  => $aplica,
            'aud_usuario'        => Auth::user()->email,
        ]);

        // Guardar días de suspensión si aplica
        if ($aplica && !empty($validated['dias_suspension'])) {
            foreach (array_unique($validated['dias_suspension']) as $fecha) {
                DiaSuspension::create([
                    'amonestacion_id' => $amonestacion->id,
                    'fecha'           => $fecha,
                    'aud_usuario'     => Auth::user()->email,
                ]);
            }
        }

        $amonestacion->load(['tipoFalta', 'diasSuspension']);

        return response()->json(['success' => true, 'data' => $amonestacion], 201);
    }

    /**
     * GET /api/rrhh/amonestaciones/{id}
     */
    public function show(int $id): JsonResponse
    {
        $amonestacion = Amonestacion::with(['tipoFalta', 'diasSuspension'])->findOrFail($id);
        $arr = $this->enrichWithEmpleadoData([$amonestacion->toArray()]);

        return response()->json(['success' => true, 'data' => $arr[0]]);
    }

    /**
     * PUT /api/rrhh/amonestaciones/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $amonestacion = Amonestacion::findOrFail($id);

        $validated = $request->validate([
            'tipo_falta_id'      => 'sometimes|exists:rrhh.tipos_falta,id',
            'fecha_amonestacion' => 'sometimes|date',
            'descripcion'        => 'sometimes|string|max:1000',
            'accion_tomada'      => 'nullable|string|max:500',
            'aplica_suspension'  => 'sometimes|boolean',
            'dias_suspension'    => 'nullable|array',
            'dias_suspension.*'  => 'date',
        ]);

        $diasSuspension = $validated['dias_suspension'] ?? null;
        unset($validated['dias_suspension']);

        $amonestacion->update(array_merge($validated, ['aud_usuario' => Auth::user()->email]));

        // Reemplazar días de suspensión si se envían
        if ($diasSuspension !== null) {
            $amonestacion->diasSuspension()->delete();

            if ($amonestacion->aplica_suspension) {
                foreach (array_unique($diasSuspension) as $fecha) {
                    DiaSuspension::create([
                        'amonestacion_id' => $amonestacion->id,
                        'fecha'           => $fecha,
                        'aud_usuario'     => Auth::user()->email,
                    ]);
                }
            }
        }

        $amonestacion->load(['tipoFalta', 'diasSuspension']);

        return response()->json(['success' => true, 'data' => $amonestacion]);
    }

    /**
     * DELETE /api/rrhh/amonestaciones/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $amonestacion = Amonestacion::findOrFail($id);
        $amonestacion->diasSuspension()->delete();
        $amonestacion->delete();

        return response()->json(['success' => true, 'message' => 'Amonestación eliminada.']);
    }
}
