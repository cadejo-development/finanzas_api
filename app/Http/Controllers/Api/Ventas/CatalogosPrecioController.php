<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaCatalogoPrecio;
use App\Models\Ventas\VentaCatalogoPrecioLinea;
use App\Models\Producto;
use Illuminate\Http\Request;

class CatalogosPrecioController extends Controller
{
    // GET /catalogos-precio
    public function index()
    {
        $catalogos = VentaCatalogoPrecio::withCount('lineas')
            ->withCount('clientes')
            ->orderBy('nombre')
            ->get()
            ->map(fn($c) => $this->format($c));

        return response()->json(['data' => $catalogos]);
    }

    // POST /catalogos-precio
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'               => 'required|string|max:100',
            'descripcion'          => 'nullable|string|max:300',
            'dias_alerta_revision' => 'integer|min:1|max:365',
        ]);

        $c = VentaCatalogoPrecio::create($data);
        return response()->json($this->format($c->loadCount(['lineas', 'clientes'])), 201);
    }

    // PATCH /catalogos-precio/{id}
    public function update(Request $request, $id)
    {
        $c = VentaCatalogoPrecio::findOrFail($id);

        $data = $request->validate([
            'nombre'               => 'sometimes|string|max:100',
            'descripcion'          => 'nullable|string|max:300',
            'dias_alerta_revision' => 'integer|min:1|max:365',
        ]);

        $c->update($data);
        return response()->json($this->format($c->loadCount(['lineas', 'clientes'])));
    }

    // PATCH /catalogos-precio/{id}/toggle
    public function toggle($id)
    {
        $c = VentaCatalogoPrecio::findOrFail($id);
        $c->update(['activo' => !$c->activo]);
        return response()->json($this->format($c->loadCount(['lineas', 'clientes'])));
    }

    // DELETE /catalogos-precio/{id}
    public function destroy($id)
    {
        $c = VentaCatalogoPrecio::withCount('clientes')->findOrFail($id);
        if ($c->clientes_count > 0) {
            return response()->json(['message' => 'No se puede eliminar: hay clientes asignados a este catálogo.'], 422);
        }
        $c->delete();
        return response()->json(null, 204);
    }

    // GET /catalogos-precio/{id}/lineas
    public function lineas($id)
    {
        VentaCatalogoPrecio::findOrFail($id);

        $lineas = VentaCatalogoPrecioLinea::with('producto')
            ->where('catalogo_id', $id)
            ->get()
            ->map(fn($l) => $this->formatLinea($l));

        return response()->json(['data' => $lineas]);
    }

    // POST /catalogos-precio/{id}/lineas
    public function storeLinea(Request $request, $id)
    {
        VentaCatalogoPrecio::findOrFail($id);

        $data = $request->validate([
            'producto_id'    => 'required|integer|exists:compras.productos,id',
            'precio_sin_iva' => 'required|numeric|min:0',
            'precio_con_iva' => 'required|numeric|min:0',
            'cantidad_minima' => 'nullable|integer|min:1',
        ]);

        $linea = VentaCatalogoPrecioLinea::updateOrCreate(
            ['catalogo_id' => $id, 'producto_id' => $data['producto_id']],
            ['precio_sin_iva' => $data['precio_sin_iva'], 'precio_con_iva' => $data['precio_con_iva']]
        );

        // Actualizar timestamp del catálogo para el indicador de revisión
        VentaCatalogoPrecio::where('id', $id)->touch();

        return response()->json($this->formatLinea($linea->load('producto')), 201);
    }

    // PATCH /catalogos-precio/{id}/lineas/{lineaId}
    public function updateLinea(Request $request, $id, $lineaId)
    {
        $linea = VentaCatalogoPrecioLinea::where('catalogo_id', $id)->findOrFail($lineaId);

        $data = $request->validate([
            'precio_sin_iva'  => 'required|numeric|min:0',
            'precio_con_iva'  => 'required|numeric|min:0',
            'cantidad_minima' => 'nullable|integer|min:1',
        ]);

        $linea->update($data);
        VentaCatalogoPrecio::where('id', $id)->touch();

        return response()->json($this->formatLinea($linea->load('producto')));
    }

    // DELETE /catalogos-precio/{id}/lineas/{lineaId}
    public function destroyLinea($id, $lineaId)
    {
        $linea = VentaCatalogoPrecioLinea::where('catalogo_id', $id)->findOrFail($lineaId);
        $linea->delete();
        return response()->json(null, 204);
    }

    // POST /catalogos-precio/{id}/ajuste-masivo
    public function ajusteMasivo(Request $request, $id)
    {
        $data = $request->validate([
            'tipo'  => 'required|in:porcentaje,monto_fijo',
            'valor' => 'required|numeric|min:0',
            'subir' => 'boolean',
        ]);

        $catalogo = VentaCatalogoPrecio::findOrFail($id);
        $lineas   = VentaCatalogoPrecioLinea::where('catalogo_id', $id)->get();
        $subir    = $data['subir'] ?? true;

        foreach ($lineas as $linea) {
            if ($data['tipo'] === 'porcentaje') {
                $factor   = $subir ? (1 + $data['valor'] / 100) : max(0, 1 - $data['valor'] / 100);
                $nuevaSin = round($linea->precio_sin_iva * $factor, 4);
                $nuevaCon = round($linea->precio_con_iva * $factor, 4);
            } else {
                $nuevaSin = $subir
                    ? round($linea->precio_sin_iva + $data['valor'], 4)
                    : round(max(0, $linea->precio_sin_iva - $data['valor']), 4);
                $nuevaCon = round($nuevaSin * 1.13, 4);
            }
            $linea->update(['precio_sin_iva' => $nuevaSin, 'precio_con_iva' => $nuevaCon]);
        }

        $catalogo->touch();
        return response()->json([
            'message'        => "Ajuste aplicado a {$lineas->count()} productos",
            'lineas_updated' => $lineas->count(),
        ]);
    }

    // PATCH /catalogos-precio/{id}/lineas/batch
    public function batchUpdate(Request $request, $id)
    {
        VentaCatalogoPrecio::findOrFail($id);

        $data = $request->validate([
            'lineas'                  => 'required|array',
            'lineas.*.id'             => 'required|integer',
            'lineas.*.precio_sin_iva' => 'required|numeric|min:0',
            'lineas.*.precio_con_iva' => 'required|numeric|min:0',
        ]);

        foreach ($data['lineas'] as $l) {
            VentaCatalogoPrecioLinea::where('catalogo_id', $id)
                ->where('id', $l['id'])
                ->update(['precio_sin_iva' => $l['precio_sin_iva'], 'precio_con_iva' => $l['precio_con_iva']]);
        }

        VentaCatalogoPrecio::where('id', $id)->touch();

        return response()->json(['message' => 'Precios actualizados', 'updated' => count($data['lineas'])]);
    }

    // GET /catalogos-precio/{id}/para-orden  (devuelve lineas indexadas por producto_id para lookup rápido)
    public function paraOrden($id)
    {
        $lineas = VentaCatalogoPrecioLinea::where('catalogo_id', $id)->get();
        $index = $lineas->keyBy('producto_id')->map(fn($l) => [
            'precio_sin_iva'  => $l->precio_sin_iva,
            'precio_con_iva'  => $l->precio_con_iva,
            'cantidad_minima' => $l->cantidad_minima ?? 1,
        ]);
        return response()->json(['data' => $index]);
    }

    private function format(VentaCatalogoPrecio $c): array
    {
        $diasSinActualizar = $c->updated_at ? (int) $c->updated_at->diffInDays(now()) : 0;
        return [
            'id'                   => $c->id,
            'nombre'               => $c->nombre,
            'descripcion'          => $c->descripcion,
            'activo'               => $c->activo,
            'dias_alerta_revision' => $c->dias_alerta_revision,
            'lineas_count'         => $c->lineas_count ?? 0,
            'clientes_count'       => $c->clientes_count ?? 0,
            'dias_sin_actualizar'  => $diasSinActualizar,
            'alerta_revision'      => $diasSinActualizar >= $c->dias_alerta_revision,
            'updated_at'           => $c->updated_at?->toDateString(),
        ];
    }

    private function formatLinea(VentaCatalogoPrecioLinea $l): array
    {
        return [
            'id'              => $l->id,
            'catalogo_id'     => $l->catalogo_id,
            'producto_id'     => $l->producto_id,
            'codigo'          => $l->producto?->codigo,
            'nombre_producto' => $l->producto?->nombre,
            'unidad'          => $l->producto?->unidad,
            'precio_sin_iva'  => $l->precio_sin_iva,
            'precio_con_iva'  => $l->precio_con_iva,
            'cantidad_minima' => $l->cantidad_minima ?? 1,
        ];
    }
}
