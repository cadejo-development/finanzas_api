<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Models\SolicitudPago;
use App\Models\SolicitudPagoAdjunto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class SolicitudPagoAdjuntoController extends Controller
{

    /**
     * Subir un archivo adjunto a una solicitud (multipart/form-data).
     * POST /api/pagos/solicitudes-pago/{solicitudId}/subir-adjunto
     */
    public function subir(Request $request, int $solicitudId): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,webp,xlsx,xls,doc,docx|max:10240',
        ]);

        $solicitud = SolicitudPago::findOrFail($solicitudId);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file          = $request->file('file');
        $nombreOriginal = $file->getClientOriginalName();
        $tipo           = $file->getMimeType();

        $path = $file->store("adjuntos/solicitudes/{$solicitudId}", 'public');
        $url  = Storage::disk('public')->url($path);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $adjunto = SolicitudPagoAdjunto::create([
            'solicitud_pago_id' => $solicitudId,
            'nombre_archivo'    => $nombreOriginal,
            'url'               => $url,
            'tipo'              => $tipo,
            'aud_usuario'       => $user?->email,
        ]);

        return response()->json(['success' => true, 'data' => $adjunto], 201);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $adjuntos = SolicitudPagoAdjunto::with('solicitud')->get();
        return response()->json(['data' => $adjuntos]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'solicitud_pago_id' => 'required|exists:pagos.solicitudes_pago,id',
            'nombre_archivo' => 'required|string|max:255',
            'url' => 'required|url',
            'tipo' => 'required|string|max:50',
            'aud_usuario' => 'required|string|max:50',
        ]);
        $adjunto = SolicitudPagoAdjunto::create($data);
        return response()->json(['data' => $adjunto], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $adjunto = SolicitudPagoAdjunto::with('solicitud')->findOrFail($id);
        return response()->json(['data' => $adjunto]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $adjunto = SolicitudPagoAdjunto::findOrFail($id);
        $data = $request->validate([
            'nombre_archivo' => 'sometimes|required|string|max:255',
            'url' => 'sometimes|required|url',
            'tipo' => 'sometimes|required|string|max:50',
            'aud_usuario' => 'sometimes|required|string|max:50',
        ]);
        $adjunto->update($data);
        return response()->json(['data' => $adjunto]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $adjunto = SolicitudPagoAdjunto::findOrFail($id);
        $adjunto->delete();
        return response()->json(['message' => 'Adjunto eliminado']);
    }
}
