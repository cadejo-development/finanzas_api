<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\TipoContrato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TipoContratoController extends RRHHBaseController
{
    /** GET /api/rrhh/mantenimiento/contratos/tipos */
    public function index(): JsonResponse
    {
        $tipos = TipoContrato::orderBy('nombre')->get();
        return response()->json(['success' => true, 'data' => $tipos]);
    }

    /** POST /api/rrhh/mantenimiento/contratos/tipos */
    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'codigo'            => 'required|string|max:30|unique:rrhh.tipos_contrato,codigo',
            'nombre'            => 'required|string|max:120',
            'descripcion'       => 'nullable|string|max:2000',
            'duracion_dias'     => 'nullable|integer|min:1|max:3650',
            'es_periodo_prueba' => 'boolean',
        ]);

        $tipo = TipoContrato::create([
            ...$v,
            'activo'      => true,
            'aud_usuario' => Auth::user()->email,
        ]);

        return response()->json(['success' => true, 'data' => $tipo], 201);
    }

    /** PUT /api/rrhh/mantenimiento/contratos/tipos/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $tipo = TipoContrato::findOrFail($id);

        $v = $request->validate([
            'codigo'            => "required|string|max:30|unique:rrhh.tipos_contrato,codigo,{$id}",
            'nombre'            => 'required|string|max:120',
            'descripcion'       => 'nullable|string|max:2000',
            'duracion_dias'     => 'nullable|integer|min:1|max:3650',
            'es_periodo_prueba' => 'boolean',
        ]);

        $tipo->update([...$v, 'aud_usuario' => Auth::user()->email]);

        return response()->json(['success' => true, 'data' => $tipo]);
    }

    /** PATCH /api/rrhh/mantenimiento/contratos/tipos/{id}/toggle */
    public function toggle(int $id): JsonResponse
    {
        $tipo = TipoContrato::findOrFail($id);
        $tipo->update(['activo' => !$tipo->activo, 'aud_usuario' => Auth::user()->email]);
        return response()->json(['success' => true, 'data' => $tipo]);
    }

    /** DELETE /api/rrhh/mantenimiento/contratos/tipos/{id} */
    public function destroy(int $id): JsonResponse
    {
        $tipo = TipoContrato::findOrFail($id);

        if ($tipo->contratos()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar: hay contratos asociados a este tipo.',
            ], 422);
        }

        $tipo->delete();
        return response()->json(['success' => true, 'message' => 'Tipo de contrato eliminado.']);
    }
}
