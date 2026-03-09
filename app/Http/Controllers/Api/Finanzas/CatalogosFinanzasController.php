<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Models\CentroCosto;
use App\Models\Contribuyente;
use App\Models\EstadoSolicitudPago;
use App\Models\Etiqueta;
use App\Models\FormaPago;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\TipoPersona;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CatalogosFinanzasController extends Controller
{
    /**
     * GET /api/pagos/catalogos
     * Todos los catalogos generales en una sola llamada.
     */
    public function index(): JsonResponse
    {
        // Si el usuario tiene CECOs asignados, filtrar el catálogo a sus centros
        $cecoQuery = CentroCosto::with('padre:id,codigo,nombre')
            ->operativos()
            ->orderBy('nombre');

        $user = Auth::user();
        if ($user->sucursal_id) {
            $cecoQuery->where('sucursal_id', $user->sucursal_id);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'sucursales'     => Sucursal::orderBy('id')->get(),
                'centros_costo'  => $cecoQuery->get(),
                'estados'        => EstadoSolicitudPago::orderBy('id')->get(),
                'contribuyentes' => Contribuyente::orderBy('id')->get(),
                'formas_pago'    => FormaPago::orderBy('id')->get(),
                'tipos_persona'  => TipoPersona::where('activo', true)->orderBy('id')->get(),
                'etiquetas'      => Etiqueta::orderBy('codigo')->get(),
                // proveedores NO se incluye aquí — usar GET /proveedores?q=texto
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
     * GET /api/pagos/proveedores?q=texto
     * Mínimo 2 caracteres. Devuelve hasta 10 coincidencias ILIKE en nombre.
     */
    public function proveedores(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $cols      = ['id', 'codigo', 'nombre', 'nit', 'banco', 'cuenta_bancaria', 'tipo_cuenta', 'tipo_persona_id', 'tipo_contribuyente_id'];
        $resultado = Proveedor::with('tipoPersona:id,codigo,nombre', 'tipoContribuyente:id,codigo,nombre')
            ->where('nombre', 'ilike', '%' . $q . '%')
            ->orderBy('nombre')
            ->limit(10)
            ->get($cols);

        return response()->json([
            'success' => true,
            'data'    => $resultado,
        ]);
    }

    /**
     * Crear nuevo proveedor
     * POST /api/pagos/proveedores
     */
    public function storeProveedor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre'         => 'required|string|max:255',
            'nit'            => 'required|string|max:50',
            'cuenta_bancaria'=> 'nullable|string|max:100',
            'tipo_cuenta'    => 'nullable|string|max:50',
            'banco'          => 'nullable|string|max:100',
            'correo'         => 'nullable|email|max:100',
            'tipo_persona_id'=> 'nullable|integer|exists:pagos.tipos_persona,id',
            'telefono'       => 'nullable|string|max:30',
            'direccion'      => 'nullable|string|max:300',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos inválidos',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $proveedor = Proveedor::create([
            'nombre'          => $request->nombre,
            'nit'             => $request->nit,
            'nrc'             => $request->input('nrc'),
            'cuenta_bancaria' => $request->input('cuenta_bancaria'),
            'tipo_cuenta'     => $request->input('tipo_cuenta'),
            'banco'           => $request->input('banco'),
            'correo'          => $request->input('correo'),
            'telefono'        => $request->input('telefono'),
            'direccion'       => $request->input('direccion'),
            'tipo_persona_id' => $request->input('tipo_persona_id'),
            'activo'          => true,
            'aud_usuario'     => auth()->user()?->email ?? 'api',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $proveedor,
        ], 201);
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