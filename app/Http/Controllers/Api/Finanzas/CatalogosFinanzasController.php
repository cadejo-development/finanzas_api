<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Models\CentroCosto;
use App\Models\Contribuyente;
use App\Models\EstadoSolicitudPago;
use App\Models\FormaPago;
use App\Models\Proveedor;
use App\Models\Sucursal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogosFinanzasController extends Controller
{
    /**
     * GET /api/pagos/catalogos
     * Todos los catalogos generales en una sola llamada.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'sucursales'     => Sucursal::orderBy('id')->get(),
                'centros_costo'  => CentroCosto::orderBy('id')->get(),
                'estados'        => EstadoSolicitudPago::orderBy('id')->get(),
                'contribuyentes' => Contribuyente::orderBy('id')->get(),
                'formas_pago'    => FormaPago::orderBy('id')->get(),
                'proveedores'    => Proveedor::orderBy('id')->get(),
            ],
        ]);
    }

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