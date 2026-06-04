<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Amonestacion;
use App\Models\RRHH\DiaSuspension;
use App\Services\RRHH\AmonestacionPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
        $validated = $request->validate([
            'empleado_id'               => 'required|integer',
            'tipo_falta_id'             => 'required|exists:rrhh.tipos_falta,id',
            'fecha_amonestacion'        => 'required|date',
            'descripcion'               => 'required|string|max:1000',
            'observacion'               => 'nullable|string|max:1000',
            'accion_tomada'             => 'nullable|string|max:500',
            'aplica_suspension'         => 'boolean',
            'dias_suspension'           => 'nullable|array|required_if:aplica_suspension,true',
            'dias_suspension.*'         => 'date',
            'aplica_suspension_propina' => 'boolean',
            'dias_suspension_propina'   => 'nullable|array|required_if:aplica_suspension_propina,true',
            'dias_suspension_propina.*' => 'date',
            'archivo'                   => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);

        $jefe = $this->getJefeEmpleado();

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
                'message' => 'Las faltas leves no pueden incluir días de suspensión.',
            ], 422);
        }

        $aplicaPropina  = $validated['aplica_suspension_propina'] ?? false;
        $diasPropina    = $aplicaPropina ? array_values(array_unique($validated['dias_suspension_propina'] ?? [])) : null;

        $archivoNombre = null;
        $archivoRuta   = null;

        if ($request->hasFile('archivo')) {
            $file          = $request->file('archivo');
            $archivoNombre = $file->getClientOriginalName();
            $archivoRuta   = $file->store('rrhh/amonestaciones', 's3');
        }

        $esDeRestaurante    = $this->esEmpleadoDeRestaurante($validated['empleado_id']);
        $esMuyGrave         = $tipoFalta?->gravedad === 'muy_grave';

        // Muy grave → aprobación de jefa RRHH (siempre, salvo admin RRHH).
        // Propina en restaurante → aprobación de gerencia_ops (solo leve/grave).
        $requiereAprobacionRrhh    = $esMuyGrave && !$this->esAdminRrhh();
        $requiereAprobacionPropina = !$esMuyGrave && $aplicaPropina && $esDeRestaurante && !$this->esAdminRrhh() && !$this->esGerenciaOps();
        $requiereAprobacion        = $requiereAprobacionRrhh || $requiereAprobacionPropina;

        $amonestacion = Amonestacion::create([
            'empleado_id'               => $validated['empleado_id'],
            'jefe_id'                   => $jefe->id,
            'tipo_falta_id'             => $validated['tipo_falta_id'],
            'fecha_amonestacion'        => $validated['fecha_amonestacion'],
            'descripcion'               => $validated['descripcion'],
            'observacion'               => $validated['observacion'] ?? null,
            'accion_tomada'             => $validated['accion_tomada'] ?? null,
            'aplica_suspension'         => $aplica,
            'aplica_suspension_propina' => $aplicaPropina,
            'dias_suspension_propina'   => $diasPropina,
            'estado'                    => $requiereAprobacionRrhh ? 'pendiente_rrhh' : ($requiereAprobacionPropina ? 'pendiente_gerencia_ops' : 'aprobado'),
            'archivo_nombre'            => $archivoNombre,
            'archivo_ruta'              => $archivoRuta,
            'aud_usuario'               => Auth::user()->email,
            'creado_por'                => $this->creadoPor(),
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

        $enriched       = $this->enrichWithEmpleadoData([$amonestacion->toArray()]);
        $empNombre      = $enriched[0]['empleado_nombre']  ?? "Empleado #{$validated['empleado_id']}";
        $sucursalNombre = $enriched[0]['sucursal_nombre']  ?? null;

        $detallesEmail = [
            'Sucursal'          => $sucursalNombre,
            'Tipo de falta'     => $amonestacion->tipoFalta?->nombre ?? '—',
            'Fecha'             => $validated['fecha_amonestacion'],
            'Descripción'       => $validated['descripcion'],
            'Aplica suspensión' => $aplica ? 'Sí' : 'No',
        ];
        if ($aplica && ! empty($validated['dias_suspension'])) {
            $detallesEmail['Días de suspensión'] = implode(', ', $validated['dias_suspension']);
        }
        if ($aplicaPropina && ! empty($diasPropina)) {
            $detallesEmail['Suspensión de propina'] = implode(', ', $diasPropina);
        }

        // Generar PDF (best-effort — no abortar si falla)
        $pdfContent = null;
        $pdfNombre  = null;
        try {
            $pdf        = AmonestacionPdfService::generar($amonestacion);
            $pdfContent = $pdf->output();
            $pdfNombre  = AmonestacionPdfService::nombreArchivo($amonestacion);
        } catch (\Throwable $e) {
            Log::warning('RRHH: Error generando PDF de amonestación', ['id' => $amonestacion->id, 'error' => $e->getMessage()]);
        }

        if ($requiereAprobacionRrhh) {
            // Muy grave → solicitar aprobación a jefa RRHH (Gabriela)
            $this->notificarJefaRRHHSolicitud(
                tipo:           'Amonestación Muy Grave',
                empleadoNombre: $empNombre,
                detalles:       $detallesEmail,
                rutaFrontend:   'amonestaciones',
                solicitudId:    $amonestacion->id,
                tipoModelo:     'amonestacion',
            );
        } elseif ($requiereAprobacionPropina) {
            // Propina en restaurante → solicitar aprobación a gerencia_ops
            $this->notificarGerenciaOpsSolicitud(
                tipo:           'Amonestación con Suspensión de Propina',
                empleadoNombre: $empNombre,
                detalles:       $detallesEmail,
                rutaFrontend:   'amonestaciones',
                solicitudId:    $amonestacion->id,
                tipoModelo:     'amonestacion',
            );
        } else {
            // Notificar directamente al empleado con PDF adjunto
            $this->notificarAlEmpleado(
                empleadoId:   $validated['empleado_id'],
                tipo:         'Amonestación',
                mensaje:      "Tu jefe inmediato ha registrado una amonestacion en tu expediente. A continuacion encontraras los detalles del registro.",
                detalles:     $detallesEmail,
                rutaFrontend: 'mi-expediente',
                pdfContent:   $pdfContent,
                pdfNombre:    $pdfNombre,
            );

            // Informar a gerencia_ops si es empleado de restaurante
            if ($esDeRestaurante) {
                $this->notificarGerenciaOps(
                    tipo:           'Amonestación',
                    empleadoNombre: $empNombre,
                    detalles:       $detallesEmail,
                    rutaFrontend:   'amonestaciones',
                );
            }
        }

        $arr = $this->enrichWithEmpleadoData([$amonestacion->toArray()]);
        return response()->json(['success' => true, 'data' => $arr[0]], 201);
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
     * GET /api/rrhh/amonestaciones/{id}/pdf
     * Genera y descarga el PDF de la amonestación.
     */
    public function pdf(int $id): Response
    {
        $amonestacion = Amonestacion::with(['tipoFalta', 'diasSuspension'])->findOrFail($id);

        $subordinadosIds = $this->getSubordinadosIds();
        if (! in_array($amonestacion->empleado_id, $subordinadosIds)) {
            abort(403, 'No tienes acceso a esta amonestación.');
        }

        $pdf    = AmonestacionPdfService::generar($amonestacion);
        $nombre = AmonestacionPdfService::nombreArchivo($amonestacion);

        return $pdf->download($nombre);
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
