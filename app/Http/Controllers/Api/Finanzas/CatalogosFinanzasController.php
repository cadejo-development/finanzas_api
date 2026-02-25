<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Models\Contribuyente;
use App\Models\FormaPago;
use App\Models\Proveedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogosFinanzasController extends Controller
{
    /**
     * Catálogo de contribuyentes
     */
    public function contribuyentes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Contribuyente::orderBy('id')->get()
        ]);
    }

    /**
     * Catálogo de formas de pago
     */
    public function formasPago(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => FormaPago::orderBy('id')->get()
        ]);
    }

    /**
     * Catálogo de proveedores
     */
    public function proveedores(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Proveedor::orderBy('id')->get()
        ]);
    }

    /**
     * Eliminar recurso (pendiente implementación)
     */
    public function destroy(string $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Método no implementado'
        ], 501);
    }
}