<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\RecetaCategoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecetaCategoriasController extends Controller
{
    // GET /api/compras/receta-categorias
    public function index(): JsonResponse
    {
        $categorias = RecetaCategoria::where('activa', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

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
