<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\RecetaCategoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecetaCategoriasController extends Controller
{
    // GET /api/compras/receta-categorias
    // Parámetros opcionales: ?sucursal_id=N  o  ?sucursal_ids[]=1&sucursal_ids[]=2
    // Si se pasan, devuelve solo categorías con ≥1 receta activa para esa(s) sucursal(es).
    public function index(Request $request): JsonResponse
    {
        $sucursalId  = $request->integer('sucursal_id', 0) ?: null;
        $sucursalIds = $request->input('sucursal_ids', []);
        if (is_array($sucursalIds)) $sucursalIds = array_map('intval', array_filter($sucursalIds));

        $query = RecetaCategoria::where('activa', true);

        if ($sucursalId || count($sucursalIds)) {
            $ids = $sucursalId ? [$sucursalId] : $sucursalIds;
            $query->whereExists(function ($sub) use ($ids) {
                $sub->from('recetas as r')
                    ->join('receta_sucursal as rs', 'rs.receta_id', '=', 'r.id')
                    ->whereColumn('r.categoria_id', 'receta_categorias.id')
                    ->where('r.activa', true)
                    ->where('rs.activa', true)
                    ->whereIn('rs.sucursal_id', $ids)
                    ->limit(1);
            });
        }

        $categorias = $query->orderBy('orden')->orderBy('nombre')->get(['id', 'nombre']);

        return response()->json(['data' => $categorias]);
    }

    // POST /api/compras/receta-categorias
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:100|unique:compras.receta_categorias,nombre',
        ]);

        $cat = RecetaCategoria::create(['nombre' => $validated['nombre'], 'activa' => true]);

        return response()->json(['data' => $cat], 201);
    }

    // PUT /api/compras/receta-categorias/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $cat = RecetaCategoria::findOrFail($id);

        $validated = $request->validate([
            'nombre' => "sometimes|string|max:100|unique:compras.receta_categorias,nombre,{$id}",
            'activa' => 'sometimes|boolean',
        ]);

        $cat->update($validated);

        return response()->json(['data' => $cat]);
    }

    // DELETE /api/compras/receta-categorias/{id}
    public function destroy(int $id): JsonResponse
    {
        $cat = RecetaCategoria::findOrFail($id);
        $cat->update(['activa' => false]);
        return response()->json(['message' => 'Categoría desactivada.']);
    }
}
