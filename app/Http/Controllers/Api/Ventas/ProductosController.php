<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaProducto;
use Illuminate\Http\Request;

class ProductosController extends Controller
{
    public function index(Request $request)
    {
        $q = VentaProducto::query()->where('activo', true);

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $q->where(function ($w) use ($search) {
                $w->whereRaw('LOWER(nombre) LIKE LOWER(?)', [$search])
                  ->orWhereRaw('LOWER(codigo) LIKE LOWER(?)', [$search]);
            });
        }

        if ($request->filled('bodega')) {
            $q->where('bodega_id', $request->bodega);
        }

        $productos = $q->orderBy('bodega')->orderBy('nombre')->get();

        return response()->json($productos->map(fn($p) => $this->format($p)));
    }

    private function format(VentaProducto $p): array
    {
        return [
            'id'          => $p->id,
            'brilo_id'    => $p->brilo_id,
            'codigo'      => $p->codigo,
            'nombre'      => $p->nombre,
            'exento'      => $p->exento,
            'precio'      => $p->precio,
            'existencias' => $p->existencias,
            'bodega'      => $p->bodega,
            'bodega_id'   => $p->bodega_id,
            'iva_pct'     => $p->exento ? 0 : 13,
        ];
    }
}
