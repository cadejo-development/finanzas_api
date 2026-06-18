<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\PlantillaContrato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PlantillaContratoController extends RRHHBaseController
{
    /** GET /api/rrhh/mantenimiento/contratos/plantillas */
    public function index(Request $request): JsonResponse
    {
        $query = PlantillaContrato::with('tipoContrato')->orderBy('nombre');

        if ($request->filled('tipo_contrato_id')) {
            $query->where('tipo_contrato_id', $request->tipo_contrato_id);
        }

        $plantillas = $query->get()->map(fn($p) => [
            'id'               => $p->id,
            'tipo_contrato_id' => $p->tipo_contrato_id,
            'tipo_nombre'      => $p->tipoContrato?->nombre,
            'cargo_id'         => $p->cargo_id,
            'cargo_nombre'     => $p->cargo_nombre,
            'nombre'           => $p->nombre,
            'version'          => $p->version,
            'activo'           => $p->activo,
            'updated_at'       => $p->updated_at,
            // 'contenido' excluido del listado (puede ser grande)
        ]);

        return response()->json(['success' => true, 'data' => $plantillas]);
    }

    /** GET /api/rrhh/mantenimiento/contratos/plantillas/{id} */
    public function show(int $id): JsonResponse
    {
        $plantilla = PlantillaContrato::with('tipoContrato')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $plantilla]);
    }

    /** POST /api/rrhh/mantenimiento/contratos/plantillas */
    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'tipo_contrato_id' => 'required|integer|exists:rrhh.tipos_contrato,id',
            'cargo_id'         => 'nullable|integer',
            'cargo_nombre'     => 'nullable|string|max:120',
            'nombre'           => 'required|string|max:200',
            'contenido'        => 'required|string',
        ]);

        // Resolver cargo_nombre desde pgsql si solo viene cargo_id
        if (!empty($v['cargo_id']) && empty($v['cargo_nombre'])) {
            $cargo = DB::connection('pgsql')->table('cargos')->find($v['cargo_id']);
            $v['cargo_nombre'] = $cargo?->nombre;
        }

        $plantilla = PlantillaContrato::create([
            ...$v,
            'activo'      => true,
            'version'     => 1,
            'aud_usuario' => Auth::user()->email,
        ]);

        $plantilla->load('tipoContrato');

        return response()->json(['success' => true, 'data' => $plantilla], 201);
    }

    /** PUT /api/rrhh/mantenimiento/contratos/plantillas/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $plantilla = PlantillaContrato::findOrFail($id);

        $v = $request->validate([
            'tipo_contrato_id' => 'required|integer|exists:rrhh.tipos_contrato,id',
            'cargo_id'         => 'nullable|integer',
            'cargo_nombre'     => 'nullable|string|max:120',
            'nombre'           => 'required|string|max:200',
            'contenido'        => 'required|string',
        ]);

        if (!empty($v['cargo_id']) && empty($v['cargo_nombre'])) {
            $cargo = DB::connection('pgsql')->table('cargos')->find($v['cargo_id']);
            $v['cargo_nombre'] = $cargo?->nombre;
        }

        $plantilla->update([
            ...$v,
            'version'     => $plantilla->version + 1,
            'aud_usuario' => Auth::user()->email,
        ]);

        $plantilla->load('tipoContrato');

        return response()->json(['success' => true, 'data' => $plantilla]);
    }

    /** PATCH /api/rrhh/mantenimiento/contratos/plantillas/{id}/toggle */
    public function toggle(int $id): JsonResponse
    {
        $plantilla = PlantillaContrato::findOrFail($id);
        $plantilla->update(['activo' => !$plantilla->activo, 'aud_usuario' => Auth::user()->email]);
        return response()->json(['success' => true, 'data' => $plantilla]);
    }

    /** DELETE /api/rrhh/mantenimiento/contratos/plantillas/{id} */
    public function destroy(int $id): JsonResponse
    {
        $plantilla = PlantillaContrato::findOrFail($id);
        $plantilla->delete();
        return response()->json(['success' => true, 'message' => 'Plantilla eliminada.']);
    }
}
