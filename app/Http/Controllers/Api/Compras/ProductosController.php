<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Sucursal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductosController extends Controller
{
    /**
     * GET /api/compras/productos
     * Lista paginada de productos activos.
     *
     * Parámetros:
     *   - categoria  (string) : key de categoría — filtra por ella
     *   - search     (string) : búsqueda en nombre o código
     *   - page       (int)    : página (default 1)
     *   - per_page   (int)    : registros por página (default 10, max 50)
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 10), 50);

        $query = Producto::with('categoria')
            ->where('activo', true)
            ->orderBy('nombre');

        // Filtro por categoría
        if ($cat = $request->query('categoria')) {
            $query->whereHas('categoria', fn ($q) => $q->where('key', $cat));
        }

        // Búsqueda por nombre o código
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'ilike', "%{$search}%")
                  ->orWhere('codigo', 'ilike', "%{$search}%");
            });
        }

        $paginado = $query->paginate($perPage);

        // Transformar para el frontend (campo categoria_key aplanado)
        $items = $paginado->getCollection()->map(fn ($p) => [
            'id'           => $p->id,
            'codigo'       => $p->codigo,
            'nombre'       => $p->nombre,
            'unidad'       => $p->unidad,
            'precio'       => (float) $p->precio,
            'precio_unitario' => (float) $p->precio,  // alias frontend
            'activo'       => $p->activo,
            'categoria_id' => $p->categoria_id,
            'categoria_key' => $p->categoria?->key,
            'categoria_nombre' => $p->categoria?->nombre,
        ]);

        return response()->json([
            'data'         => $items,
            'meta'         => [
                'current_page' => $paginado->currentPage(),
                'last_page'    => $paginado->lastPage(),
                'per_page'     => $paginado->perPage(),
                'total'        => $paginado->total(),
                'from'         => $paginado->firstItem(),
                'to'           => $paginado->lastItem(),
            ],
        ]);
    }

    /**
     * GET /api/compras/catalogos
     * Devuelve las categorías activas ordenadas.
     */
    public function catalogos(): JsonResponse
    {
        $cats = Categoria::where('activo', true)
            ->orderBy('orden')
            ->get(['id', 'key', 'nombre', 'orden']);

        return response()->json(['data' => $cats]);
    }

    /**
     * GET /api/compras/sucursales
     * Devuelve las sucursales activas (desde pgsql).
     */
    public function sucursales(): JsonResponse
    {
        $sucursales = Sucursal::orderBy('nombre')->get(['id', 'codigo', 'nombre']);

        return response()->json(['success' => true, 'data' => $sucursales]);
    }
}
