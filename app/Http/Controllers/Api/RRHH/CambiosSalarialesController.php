<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\CambioSalarial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CambiosSalarialesController extends RRHHBaseController
{
    /**
     * GET /api/rrhh/cambios-salariales
     * Acepta ?tipo_aumento_id=X para filtrar por tipo
     */
    public function index(Request $request): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();

        $query = CambioSalarial::with('tipoAumento')
            ->whereIn('empleado_id', $subordinadosIds)
            ->orderByDesc('id');

        if ($request->filled('tipo_aumento_id')) {
            $query->where('tipo_aumento_id', $request->tipo_aumento_id);
        }

        $cambios = $query->get();
        $data = $this->enrichWithEmpleadoData($cambios->toArray());

        // Agregar URL presignada de documento si existe
        $data = array_map(function ($item) {
            if (!empty($item['documento_ruta'])) {
                try {
                    $item['documento_url'] = $this->s3TemporaryUrl($item['documento_ruta'], 60);
                } catch (\Throwable) {
                    $item['documento_url'] = null;
                }
            } else {
                $item['documento_url'] = null;
            }
            return $item;
        }, $data);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/rrhh/cambios-salariales
     */
    public function store(Request $request): JsonResponse
    {
        $jefe = $this->getJefeEmpleado();

        $validated = $request->validate([
            'empleado_id'     => 'required|integer',
            'tipo_aumento_id' => 'required|exists:rrhh.tipos_aumento_salarial,id',
            'salario_anterior'=> 'required|numeric|min:0',
            'salario_nuevo'   => 'required|numeric|min:0|gt:salario_anterior',
            'fecha_efectiva'  => 'required|date',
            'justificacion'   => 'nullable|string|max:1000',
        ]);

        if (!$this->esSubordinado($validated['empleado_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'El empleado no pertenece a tu equipo.',
            ], 403);
        }

        // Calcular porcentaje automáticamente
        $porcentaje = $validated['salario_anterior'] > 0
            ? round((($validated['salario_nuevo'] - $validated['salario_anterior']) / $validated['salario_anterior']) * 100, 2)
            : null;

        $cambio = CambioSalarial::create(array_merge($validated, [
            'solicitado_por_id' => $jefe->id,
            'porcentaje'        => $porcentaje,
            'estado'            => 'pendiente',
            'aud_usuario'       => Auth::user()->email,
        ]));

        $cambio->load('tipoAumento');

        return response()->json(['success' => true, 'data' => $cambio], 201);
    }

    /**
     * GET /api/rrhh/cambios-salariales/{id}
     */
    public function show(int $id): JsonResponse
    {
        $cambio = CambioSalarial::with('tipoAumento')->findOrFail($id);
        $arr = $this->enrichWithEmpleadoData([$cambio->toArray()]);
        $item = $arr[0];

        if (!empty($item['documento_ruta'])) {
            try {
                $item['documento_url'] = $this->s3TemporaryUrl($item['documento_ruta'], 60);
            } catch (\Throwable) {
                $item['documento_url'] = null;
            }
        } else {
            $item['documento_url'] = null;
        }

        return response()->json(['success' => true, 'data' => $item]);
    }

    /**
     * PUT /api/rrhh/cambios-salariales/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $cambio = CambioSalarial::findOrFail($id);

        $validated = $request->validate([
            'tipo_aumento_id' => 'sometimes|exists:rrhh.tipos_aumento_salarial,id',
            'salario_anterior'=> 'sometimes|numeric|min:0',
            'salario_nuevo'   => 'sometimes|numeric|min:0',
            'fecha_efectiva'  => 'sometimes|date',
            'justificacion'   => 'nullable|string|max:1000',
            'estado'          => 'sometimes|in:pendiente,aprobado,rechazado',
        ]);

        // Recalcular porcentaje si cambia algún salario
        $anterior = $validated['salario_anterior'] ?? $cambio->salario_anterior;
        $nuevo    = $validated['salario_nuevo']    ?? $cambio->salario_nuevo;

        if (isset($validated['salario_anterior']) || isset($validated['salario_nuevo'])) {
            $validated['porcentaje'] = $anterior > 0
                ? round((($nuevo - $anterior) / $anterior) * 100, 2)
                : null;
        }

        $cambio->update(array_merge($validated, ['aud_usuario' => Auth::user()->email]));
        $cambio->load('tipoAumento');

        return response()->json(['success' => true, 'data' => $cambio]);
    }

    /**
     * DELETE /api/rrhh/cambios-salariales/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $cambio = CambioSalarial::findOrFail($id);
        if ($cambio->documento_ruta) {
            $this->s3DeleteKey($cambio->documento_ruta);
        }
        $cambio->delete();
        return response()->json(['success' => true, 'message' => 'Cambio salarial eliminado.']);
    }

    /**
     * GET /api/rrhh/cambios-salariales/{id}/presign
     * Genera URL presignada para subir documento de respaldo.
     */
    public function presignDocumento(Request $request, int $id): JsonResponse
    {
        CambioSalarial::findOrFail($id); // validar que existe

        $ext  = strtolower($request->query('ext', 'pdf'));
        $mime = $request->query('mime', 'application/pdf');
        $key  = "rrhh/cambios_salariales/{$id}_" . uniqid('doc_', true) . ".{$ext}";

        $client = $this->s3Client();
        $cmd    = $client->getCommand('PutObject', [
            'Bucket'      => config('filesystems.disks.s3.bucket'),
            'Key'         => $key,
            'ContentType' => $mime,
        ]);

        return response()->json([
            'success'       => true,
            'presigned_url' => (string) $client->createPresignedRequest($cmd, '+15 minutes')->getUri(),
            'key'           => $key,
        ]);
    }

    /**
     * PATCH /api/rrhh/cambios-salariales/{id}/documento
     * Guarda la referencia al documento ya subido en S3.
     */
    public function confirmarDocumento(Request $request, int $id): JsonResponse
    {
        $cambio = CambioSalarial::findOrFail($id);

        $data = $request->validate([
            'key'    => 'required|string',
            'nombre' => 'nullable|string|max:255',
            'mime'   => 'nullable|string|max:100',
        ]);

        // Borrar documento anterior si existe
        if ($cambio->documento_ruta) {
            $this->s3DeleteKey($cambio->documento_ruta);
        }

        $cambio->update([
            'documento_ruta'   => $data['key'],
            'documento_nombre' => $data['nombre'] ?? basename($data['key']),
            'documento_mime'   => $data['mime']   ?? null,
        ]);

        return response()->json([
            'success'          => true,
            'documento_url'    => $this->s3TemporaryUrl($data['key']),
            'documento_nombre' => $cambio->documento_nombre,
            'documento_mime'   => $cambio->documento_mime,
        ]);
    }

    /**
     * DELETE /api/rrhh/cambios-salariales/{id}/documento
     * Elimina el documento adjunto.
     */
    public function eliminarDocumento(int $id): JsonResponse
    {
        $cambio = CambioSalarial::findOrFail($id);

        if ($cambio->documento_ruta) {
            $this->s3DeleteKey($cambio->documento_ruta);
        }

        $cambio->update([
            'documento_ruta'   => null,
            'documento_nombre' => null,
            'documento_mime'   => null,
        ]);

        return response()->json(['success' => true, 'message' => 'Documento eliminado.']);
    }

    /** Elimina un objeto de S3 sin bloquear en caso de error. */
    private function s3DeleteKey(string $key): void
    {
        try {
            $this->s3Client()->deleteObject([
                'Bucket' => config('filesystems.disks.s3.bucket'),
                'Key'    => $key,
            ]);
        } catch (\Throwable) {
            // ignorar
        }
    }
}
