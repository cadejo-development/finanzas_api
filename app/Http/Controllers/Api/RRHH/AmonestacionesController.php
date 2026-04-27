<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Amonestacion;
use App\Models\RRHH\DiaSuspension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AmonestacionesController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;
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
            'observacion'        => 'nullable|string|max:1000',
            'accion_tomada'      => 'nullable|string|max:500',
            'aplica_suspension'  => 'boolean',
            'dias_suspension'    => 'nullable|array|required_if:aplica_suspension,true',
            'dias_suspension.*'  => 'date',
            'archivo'            => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);

        if (!$this->puedeGestionar($validated['empleado_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'El empleado no pertenece a tu equipo.',
            ], 403);
        }

        // Falta leve no puede aplicar suspensión
        $tipoFalta = \App\Models\RRHH\TipoFalta::find($validated['tipo_falta_id']);
        $aplica    = $validated['aplica_suspension'] ?? false;

        if ($aplica && $tipoFalta?->gravedad === 'leve') {
            return response()->json([
                'success' => false,
                'message' => 'Las faltas leves no pueden incluir días de suspensión. Solo las faltas graves permiten suspensión.',
            ], 422);
        }

        $archivoNombre = null;
        $archivoRuta   = null;

        if ($request->hasFile('archivo')) {
            $file          = $request->file('archivo');
            $archivoNombre = $file->getClientOriginalName();
            $archivoRuta   = $file->store('rrhh/amonestaciones', 's3');
        }

        $amonestacion = Amonestacion::create([
            'empleado_id'        => $validated['empleado_id'],
            'jefe_id'            => $jefe->id,
            'tipo_falta_id'      => $validated['tipo_falta_id'],
            'fecha_amonestacion' => $validated['fecha_amonestacion'],
            'descripcion'        => $validated['descripcion'],
            'observacion'        => $validated['observacion'] ?? null,
            'accion_tomada'      => $validated['accion_tomada'] ?? null,
            'aplica_suspension'  => $aplica,
            'archivo_nombre'     => $archivoNombre,
            'archivo_ruta'       => $archivoRuta,
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

        // Notificar al empleado amonestado
        $enriched = $this->enrichWithEmpleadoData([$amonestacion->toArray()]);
        $empNombre = $enriched[0]['empleado_nombre'] ?? "Empleado #{$validated['empleado_id']}";

        $detallesEmail = [
            'Tipo de falta'      => $amonestacion->tipoFalta?->nombre ?? '—',
            'Fecha'              => $validated['fecha_amonestacion'],
            'Descripción'        => $validated['descripcion'],
            'Aplica suspensión'  => $aplica ? 'Sí' : 'No',
        ];
        if ($aplica && ! empty($validated['dias_suspension'])) {
            $detallesEmail['Días de suspensión'] = implode(', ', $validated['dias_suspension']);
        }

        $this->notificarAlEmpleado(
            empleadoId:   $validated['empleado_id'],
            tipo:         'Amonestación',
            mensaje:      "Tu jefe inmediato ha registrado una amonestacion en tu expediente. A continuacion encontraras los detalles del registro.",
            detalles:     $detallesEmail,
            rutaFrontend: 'mi-expediente',
        );

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
            'observacion'        => 'nullable|string|max:1000',
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

        if ($amonestacion->archivo_ruta) {
            Storage::disk('s3')->delete($amonestacion->archivo_ruta);
        }

        $amonestacion->diasSuspension()->delete();
        $amonestacion->delete();

        return response()->json(['success' => true, 'message' => 'Amonestación eliminada.']);
    }

    /**
     * GET /api/rrhh/amonestaciones/{id}/descargar
     * Devuelve una URL presignada (60 min) para descargar el adjunto.
     */
    public function descargar(int $id): JsonResponse
    {
        $amonestacion = Amonestacion::findOrFail($id);

        if (!$amonestacion->archivo_ruta) {
            return response()->json(['success' => false, 'message' => 'Esta amonestación no tiene adjunto.'], 404);
        }

        $url = $this->s3TemporaryUrl($amonestacion->archivo_ruta, 60);

        return response()->json(['success' => true, 'url' => $url, 'nombre' => $amonestacion->archivo_nombre]);
    }
}
