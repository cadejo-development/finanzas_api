<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaPromocion;
use Illuminate\Http\Request;

class PromocionesVentaController extends Controller
{
    // GET /promociones-venta
    public function index()
    {
        return response()->json([
            'data' => VentaPromocion::orderBy('nombre')->get()->map(fn($p) => $this->fmt($p)),
        ]);
    }

    // GET /promociones-venta/activas  (para órdenes)
    public function activas()
    {
        return response()->json([
            'data' => VentaPromocion::where('activo', true)->orderBy('nombre')->get()->map(fn($p) => $this->fmt($p)),
        ]);
    }

    // POST /promociones-venta
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'               => 'required|string|max:120',
            'descripcion'          => 'nullable|string',
            'tipo'                 => 'sometimes|string|max:50',
            'cantidad_minima'      => 'required|integer|min:1',
            'cantidad_bonificada'  => 'required|integer|min:1',
            'aplica_mix_sku'       => 'boolean',
            'bonifica_menor_precio'=> 'boolean',
            'canal'                => 'nullable|string|max:80',
            'notas'                => 'nullable|string',
        ]);

        $p = VentaPromocion::create($data);
        return response()->json($this->fmt($p), 201);
    }

    // PATCH /promociones-venta/{id}
    public function update(Request $request, $id)
    {
        $p = VentaPromocion::findOrFail($id);

        $data = $request->validate([
            'nombre'               => 'sometimes|string|max:120',
            'descripcion'          => 'nullable|string',
            'cantidad_minima'      => 'sometimes|integer|min:1',
            'cantidad_bonificada'  => 'sometimes|integer|min:1',
            'aplica_mix_sku'       => 'boolean',
            'bonifica_menor_precio'=> 'boolean',
            'canal'                => 'nullable|string|max:80',
            'notas'                => 'nullable|string',
        ]);

        $p->update($data);
        return response()->json($this->fmt($p));
    }

    // PATCH /promociones-venta/{id}/toggle
    public function toggle($id)
    {
        $p = VentaPromocion::findOrFail($id);
        $p->update(['activo' => !$p->activo]);
        return response()->json($this->fmt($p));
    }

    // DELETE /promociones-venta/{id}
    public function destroy($id)
    {
        VentaPromocion::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    private function fmt(VentaPromocion $p): array
    {
        return [
            'id'                    => $p->id,
            'nombre'                => $p->nombre,
            'descripcion'           => $p->descripcion,
            'tipo'                  => $p->tipo,
            'cantidad_minima'       => $p->cantidad_minima,
            'cantidad_bonificada'   => $p->cantidad_bonificada,
            'aplica_mix_sku'        => $p->aplica_mix_sku,
            'bonifica_menor_precio' => $p->bonifica_menor_precio,
            'canal'                 => $p->canal,
            'notas'                 => $p->notas,
            'activo'                => $p->activo,
            'updated_at'            => $p->updated_at?->toDateString(),
        ];
    }
}
