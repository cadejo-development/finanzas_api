<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Models\PresupuestoUnidad;
use Illuminate\Http\Request;

class PresupuestoUnidadController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $presupuestos = PresupuestoUnidad::with('centroCosto')->get();
        return response()->json(['data' => $presupuestos]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'centro_costo_id' => 'required|exists:compras.centros_costo,id',
            'anio' => 'required|integer|min:2000|max:2100',
            'presupuesto_total' => 'required|numeric|min:0',
            'ejecutado' => 'required|numeric|min:0',
            'aud_usuario' => 'required|string|max:50',
        ]);
        $presupuesto = PresupuestoUnidad::create($data);
        return response()->json(['data' => $presupuesto], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $presupuesto = PresupuestoUnidad::with('centroCosto')->findOrFail($id);
        return response()->json(['data' => $presupuesto]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $presupuesto = PresupuestoUnidad::findOrFail($id);
        $data = $request->validate([
            'centro_costo_id' => 'sometimes|required|exists:compras.centros_costo,id',
            'anio' => 'sometimes|required|integer|min:2000|max:2100',
            'presupuesto_total' => 'sometimes|required|numeric|min:0',
            'ejecutado' => 'sometimes|required|numeric|min:0',
            'aud_usuario' => 'sometimes|required|string|max:50',
        ]);
        $presupuesto->update($data);
        return response()->json(['data' => $presupuesto]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $presupuesto = PresupuestoUnidad::findOrFail($id);
        $presupuesto->delete();
        return response()->json(['message' => 'Presupuesto eliminado']);
    }
}
