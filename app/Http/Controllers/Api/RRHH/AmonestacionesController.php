<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Amonestacion;
use App\Models\RRHH\DiaSuspension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AmonestacionesController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    /**
     * GET /api/rrhh/amonestaciones
     */
    public function index(): JsonResponse
    {
        // Admins y analistas ven TODAS las amonestaciones (incluye empleados inactivos/desvinculados).
        // Jefatura solo ve las de su equipo activo.
        if ($this->esAdminRrhh() || $this->esAnalistaRrhh()) {
            $query = Amonestacion::with(['tipoFalta', 'diasSuspension'])->orderByDesc('id');
        } else {
            $subordinadosIds = $this->getSubordinadosIds();
            $query = Amonestacion::with(['tipoFalta', 'diasSuspension'])
                ->whereIn('empleado_id', $subordinadosIds)
                ->orderByDesc('id');
        }

        $amonestaciones = $query->get();
        $enriched = $this->enrichWithEmpleadoData($amonestaciones->toArray());

        // Fallback: si el nombre no pudo resolverse en core, mostrar referencia por ID
        $data = array_map(function ($item) {
            if (empty($item['empleado_nombre'])) {
                $item['empleado_nombre'] = 'Empleado #' . ($item['empleado_id'] ?? '?');
            }
            return $item;
        }, $enriched);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/rrhh/amonestaciones
     */
    public function store(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
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

            $enriched = $this->enrichWithEmpleadoData([$amonestacion->toArray()]);
            $data = $enriched[0];
            if (empty($data['empleado_nombre'])) {
                $data['empleado_nombre'] = 'Empleado #' . ($data['empleado_id'] ?? '?');
            }

            $detallesNotif = [
                'Tipo de falta'  => $amonestacion->tipoFalta?->nombre ?? '—',
                'Fecha'          => $validated['fecha_amonestacion'],
                'Descripción'    => $validated['descripcion'],
                'Acción tomada'  => $validated['accion_tomada'] ?? '—',
                'Suspensión'     => $aplica ? 'Sí' : 'No',
                'Cargo'          => $data['cargo_nombre'] ?? '—',
                'Sucursal'       => $data['sucursal_nombre'] ?? '—',
            ];

            $this->notificarAdminsRrhh(
                tipo:           'Amonestación / Falta Grave',
                empleadoNombre: $data['empleado_nombre'],
                detalles:       $detallesNotif,
                rutaFrontend:   'amonestaciones',
            );
            $this->notificarGerenciaOps(
                tipo:           'Amonestación / Falta Grave',
                empleadoNombre: $data['empleado_nombre'],
                detalles:       $detallesNotif,
                rutaFrontend:   'amonestaciones',
            );

            return response()->json(['success' => true, 'data' => $data], 201);
        });
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
        return $this->captureAndRespond($request, function () use ($request, $id) {
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

            $enriched = $this->enrichWithEmpleadoData([$amonestacion->toArray()]);
            $data = $enriched[0];
            if (empty($data['empleado_nombre'])) {
                $data['empleado_nombre'] = 'Empleado #' . ($data['empleado_id'] ?? '?');
            }

            return response()->json(['success' => true, 'data' => $data]);
        });
    }

    /**
     * DELETE /api/rrhh/amonestaciones/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $amonestacion = Amonestacion::findOrFail($id);
            $amonestacion->diasSuspension()->delete();
            $amonestacion->delete();

            return response()->json(['success' => true, 'message' => 'Amonestación eliminada.']);
        });
    }
}
