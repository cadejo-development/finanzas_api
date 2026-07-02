<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use Illuminate\Http\Request;

class ProductosController extends Controller
{
    // GET /api/productos?search=cerveza&limit=50
    public function index(Request $request)
    {
        $q = Producto::query()
            ->where('activo', true)
            ->whereIn('origen', ['restaurante'])
            ->where('precio', '>', 0);

        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $q->where(function ($w) use ($s) {
                $w->whereRaw('LOWER(nombre) LIKE LOWER(?)', [$s])
                  ->orWhereRaw('LOWER(codigo) LIKE LOWER(?)', [$s]);
            });
        }

        $limit = min((int) $request->get('limit', 80), 200);

        $productos = $q->orderBy('nombre')->limit($limit)->get();

        return response()->json($productos->map(fn($p) => $this->format($p)));
    }

    private function format(Producto $p): array
    {
        return [
            'id'     => $p->id,
            'codigo' => $p->codigo,
            'nombre' => $p->nombre,
            'unidad' => $p->unidad,
            'precio' => $p->precio,
            'costo'  => $p->costo,
            'exento' => false,
        ];
    }
}
