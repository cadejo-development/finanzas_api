<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\KpiBonoPuesto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class KpiBonoPuestoController extends Controller
{
    /**
     * GET /api/rrhh/admin/kpi-bono-puesto
     * Lista todos los bonos configurados por puesto, enriquecidos con nombre de cargo y sucursal.
     */
    public function index(): JsonResponse
    {
        $bonos = KpiBonoPuesto::with(['cargo:id,nombre,codigo', 'sucursal:id,nombre'])
            ->orderBy('cargo_id')
            ->get()
            ->map(fn($b) => [
                'id'            => $b->id,
                'cargo_id'      => $b->cargo_id,
                'cargo_nombre'  => $b->cargo?->nombre,
                'cargo_codigo'  => $b->cargo?->codigo,
                'sucursal_id'   => $b->sucursal_id,
                'sucursal_nombre' => $b->sucursal?->nombre,
                'monto_bono'    => $b->monto_bono,
                'umbral_minimo' => $b->umbral_minimo,
            ]);

        return response()->json($bonos);
    }

    /**
     * POST /api/rrhh/admin/kpi-bono-puesto
     * Crea o actualiza (upsert) el bono de un puesto/sucursal.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cargo_id'      => 'required|integer|exists:pgsql.cargos,id',
            'sucursal_id'   => 'nullable|integer|exists:pgsql.sucursales,id',
            'monto_bono'    => 'required|numeric|min:0.01',
            'umbral_minimo' => 'required|numeric|min:0|max:100',
        ]);

        $bono = KpiBonoPuesto::updateOrCreate(
            ['cargo_id' => $data['cargo_id'], 'sucursal_id' => $data['sucursal_id'] ?? null],
            [
                'monto_bono'    => $data['monto_bono'],
                'umbral_minimo' => $data['umbral_minimo'],
                'aud_usuario'   => Auth::user()?->email ?? 'sistema',
            ]
        );

        $bono->load(['cargo:id,nombre,codigo', 'sucursal:id,nombre']);

        return response()->json([
            'id'              => $bono->id,
            'cargo_id'        => $bono->cargo_id,
            'cargo_nombre'    => $bono->cargo?->nombre,
            'cargo_codigo'    => $bono->cargo?->codigo,
            'sucursal_id'     => $bono->sucursal_id,
            'sucursal_nombre' => $bono->sucursal?->nombre,
            'monto_bono'      => $bono->monto_bono,
            'umbral_minimo'   => $bono->umbral_minimo,
        ], 201);
    }

    /**
     * DELETE /api/rrhh/admin/kpi-bono-puesto/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        KpiBonoPuesto::findOrFail($id)->delete();
        return response()->json(['message' => 'Bono de puesto eliminado.']);
    }
}
