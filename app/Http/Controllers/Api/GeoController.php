<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeoDepar;
use App\Models\GeoDistrito;
use App\Models\GeoMunicipio;
use Illuminate\Http\JsonResponse;

class GeoController extends Controller
{
    /** GET /api/geo/departamentos */
    public function departamentos(): JsonResponse
    {
        $deptos = GeoDepar::orderBy('codigo')->get(['id', 'codigo', 'nombre']);
        return response()->json(['success' => true, 'data' => $deptos]);
    }

    /** GET /api/geo/departamentos/{id}/distritos */
    public function distritos(int $deptoId): JsonResponse
    {
        $distritos = GeoDistrito::where('departamento_id', $deptoId)
            ->orderBy('nombre')
            ->get(['id', 'departamento_id', 'codigo', 'nombre']);

        return response()->json(['success' => true, 'data' => $distritos]);
    }

    /** GET /api/geo/distritos/{id}/municipios */
    public function municipios(int $distritoId): JsonResponse
    {
        $municipios = GeoMunicipio::where('distrito_id', $distritoId)
            ->orderBy('nombre')
            ->get(['id', 'departamento_id', 'distrito_id', 'nombre']);

        return response()->json(['success' => true, 'data' => $municipios]);
    }
}
