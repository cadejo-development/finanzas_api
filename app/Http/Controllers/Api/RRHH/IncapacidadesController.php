<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\AusenciaInjustificada;
use App\Models\RRHH\Incapacidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class IncapacidadesController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;
    /**
     * GET /api/rrhh/incapacidades
     */
    public function index(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () {
            $subordinadosIds = $this->getSubordinadosIds();

            // Incluir al propio jefe para que vea sus propias solicitudes
            try {
                $propioId = $this->getJefeEmpleado()->id;
                $subordinadosIds = array_values(array_unique(array_merge($subordinadosIds, [$propioId])));
            } catch (\Throwable) {}

            $incapacidades = Incapacidad::with('tipoIncapacidad')
                ->whereIn('empleado_id', $subordinadosIds)
                ->orderByDesc('id')
                ->get();

            $data = $this->enrichWithEmpleadoData($incapacidades->toArray());

            return response()->json(['success' => true, 'data' => $data]);
        });
    }

    /**
     * POST /api/rrhh/incapacidades
     */
    public function store(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $validated = $request->validate([
                'empleado_id'        => 'required|integer',
                'tipo_incapacidad_id'=> 'required|exists:rrhh.tipos_incapacidad,id',
                'tipo_institucion'   => 'nullable|in:isss,privada',
                'nombre_institucion' => 'nullable|required_if:tipo_institucion,privada|string|max:150',
                'medico_tratante'    => 'nullable|string|max:150',
                'numero_certificado' => 'nullable|string|max:80',
                'fecha_inicio'       => 'required|date',
                'fecha_fin'          => 'required|date|after_or_equal:fecha_inicio',
                'dias'               => 'nullable|integer|min:1',  // auto-calculado si no se envía
                'observaciones'      => 'nullable|string|max:500',
                'archivo'            => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            ]);

            $jefe = $this->getJefeEmpleado();

            if (!$this->puedeGestionar($validated['empleado_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'El empleado no pertenece a tu equipo.',
                ], 403);
            }

            // Calcular días automáticamente si el frontend no los envió
            $dias = $validated['dias'] ?? (
                \Carbon\Carbon::parse($validated['fecha_inicio'])
                    ->diffInDays(\Carbon\Carbon::parse($validated['fecha_fin'])) + 1
            );

            $archivoNombre = null;
            $archivoRuta   = null;

            if ($request->hasFile('archivo')) {
                $file          = $request->file('archivo');
                $archivoNombre = $file->getClientOriginalName();
                $archivoRuta   = $file->store('rrhh/incapacidades', 's3');
            }

            $incapacidad = Incapacidad::create([
                'empleado_id'         => $validated['empleado_id'],
                'tipo_incapacidad_id' => $validated['tipo_incapacidad_id'],
                'tipo_institucion'    => $validated['tipo_institucion'] ?? null,
                'nombre_institucion'  => $validated['nombre_institucion'] ?? null,
                'medico_tratante'     => $validated['medico_tratante'] ?? null,
                'numero_certificado'  => $validated['numero_certificado'] ?? null,
                'registrado_por_id'   => $jefe->id,
                'fecha_inicio'        => $validated['fecha_inicio'],
                'fecha_fin'           => $validated['fecha_fin'],
                'dias'                => $dias,
                'observaciones'       => $validated['observaciones'] ?? null,
                'archivo_nombre'      => $archivoNombre,
                'archivo_ruta'        => $archivoRuta,
                'homologada'          => false,
                'aud_usuario'         => Auth::user()->email,
            ]);

            $incapacidad->load('tipoIncapacidad');

            // Marcar ausencias injustificadas como cubiertas por esta incapacidad (solo ISSS, ≤ 3 días)
            // El registro de ausencia se conserva pero no se descuenta al empleado
            if (($validated['tipo_institucion'] ?? null) === 'isss' && $dias <= 3) {
                AusenciaInjustificada::where('empleado_id', $validated['empleado_id'])
                    ->whereBetween('fecha', [$validated['fecha_inicio'], $validated['fecha_fin']])
                    ->whereNull('cubierta_por_incapacidad_id')
                    ->update(['cubierta_por_incapacidad_id' => $incapacidad->id]);
            }

            // Notify supervisor when employee submits own incapacidad (informational)
            if ($this->debeNotificar($validated['empleado_id'])) {
                $institucionLabel = match ($validated['tipo_institucion'] ?? null) {
                    'isss'    => 'ISSS',
                    'privada' => 'Privada',
                    default   => null,
                };
                $detalles = array_filter([
                    'Tipo'        => $incapacidad->tipoIncapacidad?->nombre,
                    'Institución' => $institucionLabel,
                    'Desde'       => $validated['fecha_inicio'],
                    'Hasta'       => $validated['fecha_fin'],
                    'Días'        => $dias . ' día(s)',
                    'Observaciones' => $validated['observaciones'] ?? null,
                ]);
                $this->notificarAccion($validated['empleado_id'], 'Incapacidad', $detalles, 'incapacidades');
            }

            return response()->json(['success' => true, 'data' => $incapacidad], 201);
        });
    }

    /**
     * GET /api/rrhh/incapacidades/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($id) {
            $incapacidad = Incapacidad::with('tipoIncapacidad')->findOrFail($id);
            $arr = $this->enrichWithEmpleadoData([$incapacidad->toArray()]);

            return response()->json(['success' => true, 'data' => $arr[0]]);
        });
    }

    /**
     * PUT /api/rrhh/incapacidades/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request, $id) {
            $incapacidad = Incapacidad::findOrFail($id);

            $validated = $request->validate([
                'tipo_incapacidad_id' => 'sometimes|exists:rrhh.tipos_incapacidad,id',
                'tipo_institucion'    => 'nullable|in:isss,privada',
                'fecha_inicio'        => 'sometimes|date',
                'fecha_fin'           => 'sometimes|date|after_or_equal:fecha_inicio',
                'dias'                => 'sometimes|integer|min:1',
                'observaciones'       => 'nullable|string|max:500',
            ]);

            $incapacidad->update(array_merge($validated, ['aud_usuario' => Auth::user()->email]));
            $incapacidad->load('tipoIncapacidad');

            return response()->json(['success' => true, 'data' => $incapacidad]);
        });
    }

    /**
     * DELETE /api/rrhh/incapacidades/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($id) {
            $incapacidad = Incapacidad::findOrFail($id);

            if ($incapacidad->archivo_ruta) {
                Storage::disk('s3')->delete($incapacidad->archivo_ruta);
            }

            // Revertir el marcado en ausencias que esta incapacidad cubría
            AusenciaInjustificada::where('cubierta_por_incapacidad_id', $incapacidad->id)
                ->update(['cubierta_por_incapacidad_id' => null]);

            $incapacidad->delete();

            return response()->json(['success' => true, 'message' => 'Incapacidad eliminada.']);
        });
    }

    /**
     * Marcar incapacidad como homologada.
     * PATCH /api/rrhh/incapacidades/{id}/homologar
     */
    public function homologar(Request $request, int $id): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($id) {
            $jefe        = $this->getJefeEmpleado();
            $incapacidad = Incapacidad::findOrFail($id);

            $incapacidad->update([
                'homologada'        => true,
                'homologada_por_id' => $jefe->id,
                'homologada_en'     => now(),
                'aud_usuario'       => Auth::user()->email,
            ]);

            return response()->json(['success' => true, 'data' => $incapacidad]);
        });
    }
}
