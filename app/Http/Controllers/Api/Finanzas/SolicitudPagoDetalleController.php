<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Models\SolicitudPagoDetalle;
use Illuminate\Http\Request;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class SolicitudPagoDetalleController extends Controller
{

    
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $detalles = SolicitudPagoDetalle::with(['solicitud', 'centroCosto'])->get();
        return response()->json(['data' => $detalles]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'solicitud_pago_id' => 'required|exists:pagos.solicitudes_pago,id',
            'concepto' => 'required|string|max:255',
            'centro_costo_id' => 'required|exists:compras.centros_costo,id',
            'cantidad' => 'required|numeric|min:0.01',
            'precio_unitario' => 'required|numeric|min:0',
            'subtotal_linea' => 'required|numeric|min:0',
            'aud_usuario' => 'required|string|max:50',
        ]);
        $detalle = SolicitudPagoDetalle::create($data);
        return response()->json(['data' => $detalle], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $detalle = SolicitudPagoDetalle::with(['solicitud', 'centroCosto'])->findOrFail($id);
        return response()->json(['data' => $detalle]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $detalle = SolicitudPagoDetalle::findOrFail($id);
        $data = $request->validate([
            'concepto' => 'sometimes|required|string|max:255',
            'centro_costo_id' => 'sometimes|required|exists:compras.centros_costo,id',
            'cantidad' => 'sometimes|required|numeric|min:0.01',
            'precio_unitario' => 'sometimes|required|numeric|min:0',
            'subtotal_linea' => 'sometimes|required|numeric|min:0',
            'aud_usuario' => 'sometimes|required|string|max:50',
        ]);
        $detalle->update($data);
        return response()->json(['data' => $detalle]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $detalle = SolicitudPagoDetalle::findOrFail($id);
        $detalle->delete();
        return response()->json(['message' => 'Detalle eliminado']);
    }
}
