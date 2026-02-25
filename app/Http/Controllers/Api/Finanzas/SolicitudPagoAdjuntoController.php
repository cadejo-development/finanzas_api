
<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Models\SolicitudPagoAdjunto;
use Illuminate\Http\Request;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class SolicitudPagoAdjuntoController extends Controller
{

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
