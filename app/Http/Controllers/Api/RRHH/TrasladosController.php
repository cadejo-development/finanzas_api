<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Traslado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TrasladosController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    /**
     * GET /api/rrhh/traslados
     */
    public function index(): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();

        $traslados = Traslado::whereIn('empleado_id', $subordinadosIds)
            ->orderByDesc('id')
            ->get();

        $data = $this->enrichWithEmpleadoData($traslados->toArray());

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/rrhh/traslados
     */
    public function store(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $jefe = $this->getJefeEmpleado();

            $validated = $request->validate([
                'empleado_id'          => 'required|integer',
                'sucursal_destino_id'  => 'required|integer',
                'cargo_destino_id'     => 'nullable|integer',
                'fecha_efectiva'       => 'required|date',
                'motivo'               => 'nullable|string|max:500',
            ]);

            if (!$this->puedeGestionar($validated['empleado_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'El empleado no pertenece a tu equipo.',
                ], 403);
            }

            // Capturar datos actuales del empleado (origen)
            $empData = DB::connection('pgsql')
                ->table('empleados as e')
                ->leftJoin('cargos as c', 'e.cargo_id', '=', 'c.id')
                ->leftJoin('sucursales as s', 'e.sucursal_id', '=', 's.id')
                ->where('e.id', $validated['empleado_id'])
                ->select('e.sucursal_id', 's.nombre as sucursal_nombre', 'e.cargo_id', 'c.nombre as cargo_nombre')
                ->first();

            // Datos de destino
            $sucursalDestino = DB::connection('pgsql')
                ->table('sucursales')
                ->where('id', $validated['sucursal_destino_id'])
                ->value('nombre');

            $cargoDestinoId = $validated['cargo_destino_id'] ?? null;
            $cargoDestino = null;
            if ($cargoDestinoId) {
                $cargoDestino = DB::connection('pgsql')
                    ->table('cargos')
                    ->where('id', $cargoDestinoId)
                    ->value('nombre');
            }

            // Auto-aprobar si el creador gestiona tanto el origen como el destino
            // (gerente con equipo en ambas sucursales, o admin/analista).
            $estado = 'pendiente';
            if ($this->esAdminRrhh() || $this->esAnalistaRrhh()) {
                $estado = 'aprobado';
            } else {
                $sucursalesDelJefe = DB::connection('pgsql')
                    ->table('empleados')
                    ->whereIn('id', $this->getSubordinadosIds())
                    ->whereNotNull('sucursal_id')
                    ->pluck('sucursal_id')
                    ->unique()
                    ->toArray();
                if (in_array((int) $validated['sucursal_destino_id'], $sucursalesDelJefe)) {
                    $estado = 'aprobado';
                }
            }

            $traslado = Traslado::create([
                'empleado_id'            => $validated['empleado_id'],
                'solicitado_por_id'      => $jefe->id,
                'sucursal_origen_id'     => $empData?->sucursal_id,
                'sucursal_origen_nombre' => $empData?->sucursal_nombre,
                'cargo_origen_id'        => $empData?->cargo_id,
                'cargo_origen_nombre'    => $empData?->cargo_nombre,
                'sucursal_destino_id'    => $validated['sucursal_destino_id'],
                'sucursal_destino_nombre'=> $sucursalDestino,
                'cargo_destino_id'       => $cargoDestinoId,
                'cargo_destino_nombre'   => $cargoDestino,
                'fecha_efectiva'         => $validated['fecha_efectiva'],
                'motivo'                 => $validated['motivo'] ?? null,
                'estado'                 => $estado,
                'aud_usuario'            => Auth::user()->email,
            ]);

            // Aplicar si aprobado y la fecha efectiva ya llegó (pasada o hoy)
            if ($estado === 'aprobado' && $traslado->fecha_efectiva->lte(now())) {
                $this->aplicarTraslado($traslado);
            }

            $arr = $this->enrichWithEmpleadoData([$traslado->toArray()]);
            $empleadoNombreNotif = $arr[0]['empleado_nombre'] ?? "Empleado #{$validated['empleado_id']}";

            $detallesNotif = [
                'Origen'         => $empData?->sucursal_nombre ?? '—',
                'Destino'        => $sucursalDestino ?? '—',
                'Cargo destino'  => $cargoDestino ?? '(sin cambio de cargo)',
                'Fecha efectiva' => $traslado->fecha_efectiva->format('d/m/Y'),
                'Estado'         => ucfirst($estado),
                'Motivo'         => $validated['motivo'] ?? '—',
            ];

            $this->notificarAdminsRrhh(
                tipo:            'Traslado de Personal',
                empleadoNombre:  $empleadoNombreNotif,
                detalles:        $detallesNotif,
                rutaFrontend:    'traslados',
            );
            $this->notificarGerenciaOps(
                tipo:            'Traslado de Personal',
                empleadoNombre:  $empleadoNombreNotif,
                detalles:        $detallesNotif,
                rutaFrontend:    'traslados',
            );

            return response()->json(['success' => true, 'data' => $arr[0]], 201);
        });
    }

    /**
     * GET /api/rrhh/traslados/{id}
     */
    public function show(int $id): JsonResponse
    {
        $traslado = Traslado::findOrFail($id);
        $arr = $this->enrichWithEmpleadoData([$traslado->toArray()]);

        return response()->json(['success' => true, 'data' => $arr[0]]);
    }

    /**
     * PUT /api/rrhh/traslados/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request, $id) {
            $traslado = Traslado::findOrFail($id);

            $validated = $request->validate([
                'sucursal_destino_id' => 'sometimes|integer',
                'cargo_destino_id'    => 'nullable|integer',
                'fecha_efectiva'      => 'sometimes|date',
                'motivo'              => 'nullable|string|max:500',
                'estado'              => 'sometimes|in:pendiente,aprobado,rechazado',
            ]);

            // Actualizar nombres denormalizados si cambia sucursal destino
            if (isset($validated['sucursal_destino_id'])) {
                $validated['sucursal_destino_nombre'] = DB::connection('pgsql')
                    ->table('sucursales')
                    ->where('id', $validated['sucursal_destino_id'])
                    ->value('nombre');
            }

            if (array_key_exists('cargo_destino_id', $validated) && $validated['cargo_destino_id']) {
                $validated['cargo_destino_nombre'] = DB::connection('pgsql')
                    ->table('cargos')
                    ->where('id', $validated['cargo_destino_id'])
                    ->value('nombre');
            }

            $traslado->update(array_merge($validated, ['aud_usuario' => Auth::user()->email]));

            return response()->json(['success' => true, 'data' => $traslado]);
        });
    }

    /**
     * DELETE /api/rrhh/traslados/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            Traslado::findOrFail($id)->delete();
            return response()->json(['success' => true, 'message' => 'Traslado eliminado.']);
        });
    }

    /**
     * Aplica el traslado aprobado al registro del empleado.
     * - Cambio de plaza (misma sucursal): actualiza cargo_id y plaza_id
     * - Traslado real (distinta sucursal): actualiza sucursal_id y departamento_id
     */
    private function aplicarTraslado(\App\Models\RRHH\Traslado $traslado): void
    {
        $updates = ['updated_at' => now()];

        $esCambioDePlaza = $traslado->sucursal_origen_id &&
            (int) $traslado->sucursal_origen_id === (int) $traslado->sucursal_destino_id;

        if ($esCambioDePlaza) {
            // Solo cambia el cargo; la sucursal/departamento no se mueve
            if ($traslado->cargo_destino_id) {
                $updates['cargo_id'] = $traslado->cargo_destino_id;
            }
        } else {
            // Traslado a otra sucursal: mover sucursal y limpiar departamento
            // (el departamento en destino lo asigna RRHH cuando corresponda)
            $updates['sucursal_id'] = $traslado->sucursal_destino_id;
            if ($traslado->cargo_destino_id) {
                $updates['cargo_id'] = $traslado->cargo_destino_id;
            }
        }

        DB::connection('pgsql')
            ->table('empleados')
            ->where('id', $traslado->empleado_id)
            ->update($updates);

        $traslado->update(['aplicado_at' => now()]);
    }
}
