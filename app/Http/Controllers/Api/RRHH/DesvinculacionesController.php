<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Desvinculacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DesvinculacionesController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    /**
     * GET /api/rrhh/desvinculaciones
     * Acepta ?tipo=despido|renuncia para filtrar
     */
    public function index(Request $request): JsonResponse
    {
        // Las desvinculaciones son de empleados ya dados de baja (activo=false),
        // por lo que no se puede filtrar por getSubordinadosIds() (solo activos).
        if ($this->esAdminRrhh() || $this->esAnalistaRrhh()) {
            $query = Desvinculacion::with('motivo')->orderByDesc('id');
        } else {
            // Jefatura: solo ve las que él procesó
            try {
                $jefeId = $this->getJefeEmpleado()->id;
            } catch (\Throwable) {
                return response()->json(['success' => true, 'data' => []]);
            }
            $query = Desvinculacion::with('motivo')
                ->where('procesado_por_id', $jefeId)
                ->orderByDesc('id');
        }

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        $desvinculaciones = $query->get();
        $original = $desvinculaciones->toArray();
        $enriched = $this->enrichWithEmpleadoData($original);

        // Restaurar datos denormalizados si el empleado ya no está activo en core
        $data = array_map(function ($item, $orig) {
            if (empty($item['empleado_nombre'])) {
                $item['empleado_nombre'] = $orig['empleado_nombre'] ?? null;
                $item['cargo_nombre']    = $orig['cargo_nombre']    ?? $item['cargo_nombre'];
                $item['sucursal_nombre'] = $orig['sucursal_nombre'] ?? $item['sucursal_nombre'];
            }
            return $item;
        }, $enriched, $original);

        return response()->json(['success' => true, 'data' => array_values($data)]);
    }

    /**
     * POST /api/rrhh/desvinculaciones
     */
    public function store(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $jefe = $this->getJefeEmpleado();

            $validated = $request->validate([
                'empleado_id'   => 'required|integer',
                'motivo_id'     => 'required|exists:rrhh.motivos_desvinculacion,id',
                'tipo'          => 'required|in:despido,renuncia',
                'fecha_efectiva'=> 'required|date',
                'observaciones' => 'nullable|string|max:1000',
            ]);

            if (!$this->puedeGestionar($validated['empleado_id'])) {
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

            return response()->json(['success' => true, 'data' => $desvinculacion], 201);
        });
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
        return $this->captureAndRespond($request, function () use ($request, $id) {
            $desvinculacion = Desvinculacion::findOrFail($id);

            $validated = $request->validate([
                'motivo_id'     => 'sometimes|exists:rrhh.motivos_desvinculacion,id',
                'fecha_efectiva'=> 'sometimes|date',
                'observaciones' => 'nullable|string|max:1000',
            ]);

            $desvinculacion->update(array_merge($validated, ['aud_usuario' => Auth::user()->email]));
            $desvinculacion->load('motivo');

            return response()->json(['success' => true, 'data' => $desvinculacion]);
        });
    }

    /**
     * GET /api/rrhh/desvinculaciones/{id}/descargar
     * Devuelve una URL pre-firmada S3 con Content-Disposition: attachment para forzar descarga.
     */
    public function descargar(int $id): JsonResponse
    {
        $desvinculacion = Desvinculacion::findOrFail($id);

        if (empty($desvinculacion->archivo_ruta)) {
            return response()->json(['success' => false, 'message' => 'Esta desvinculación no tiene archivo adjunto.'], 404);
        }

        try {
            $nombre = $desvinculacion->archivo_nombre ?? basename($desvinculacion->archivo_ruta);

            // ResponseContentDisposition fuerza al browser a descargar en lugar de abrir inline
            $client = $this->s3Client();
            $cmd = $client->getCommand('GetObject', [
                'Bucket'                     => config('filesystems.disks.s3.bucket'),
                'Key'                        => $desvinculacion->archivo_ruta,
                'ResponseContentDisposition' => 'attachment; filename="' . rawurlencode($nombre) . '"',
            ]);
            $url = (string) $client->createPresignedRequest($cmd, '+15 minutes')->getUri();

            return response()->json(['success' => true, 'url' => $url, 'nombre' => $nombre]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('DesvinculacionesController::descargar: ' . $e->getMessage(), [
                'desvinculacion_id' => $id,
                'archivo_ruta'      => $desvinculacion->archivo_ruta,
            ]);
            return response()->json(['success' => false, 'message' => 'No se pudo obtener el enlace de descarga.'], 500);
        }
    }

    /**
     * DELETE /api/rrhh/desvinculaciones/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            Desvinculacion::findOrFail($id)->delete();
            return response()->json(['success' => true, 'message' => 'Desvinculación eliminada.']);
        });
    }
}
