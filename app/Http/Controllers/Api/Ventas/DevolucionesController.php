<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaDevolucion;
use App\Models\Ventas\VentaDevolucionItem;
use App\Models\Ventas\VentaOrden;
use App\Models\Ventas\VentaProducto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DevolucionesController extends Controller
{
    public function index(Request $request)
    {
        $q = VentaDevolucion::with('cliente')->latest();

        if ($request->filled('estado')) {
            $q->where('estado', $request->estado);
        }

        if ($request->filled('cliente_id')) {
            $q->where('cliente_id', $request->cliente_id);
        }

        $devoluciones = $q->paginate(30);
        $devoluciones->getCollection()->transform(fn($d) => $this->format($d));

        return response()->json($devoluciones);
    }

    public function show($id)
    {
        $dev = VentaDevolucion::with(['cliente', 'orden', 'items.producto'])->findOrFail($id);
        return response()->json($this->formatDetalle($dev));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'orden_id'          => 'nullable|integer',
            'cliente_id'        => 'required|integer',
            'tipo'              => 'required|in:devolucion,cambio',
            'motivo'            => 'nullable|string',
            'notas'             => 'nullable|string',
            'creado_por'        => 'nullable|string',
            'items'             => 'required|array|min:1',
            'items.*.producto_id'     => 'required|integer',
            'items.*.nombre_producto' => 'nullable|string',
            'items.*.cantidad'        => 'required|numeric|min:0.01',
            'items.*.precio_unitario' => 'required|numeric|min:0',
            'items.*.motivo_item'     => 'nullable|string',
        ]);

        DB::connection('compras')->transaction(function () use ($data, &$devolucion) {
            $subtotal = 0;
            $totalIva = 0;
            $itemsData = [];

            foreach ($data['items'] as $item) {
                $producto = VentaProducto::findOrFail($item['producto_id']);
                $sub = round($item['cantidad'] * $item['precio_unitario'], 2);
                $iva = $producto->exento ? 0 : round($sub * 0.13, 2);
                $subtotal += $sub;
                $totalIva += $iva;
                $itemsData[] = [
                    'producto_id'     => $item['producto_id'],
                    'nombre_producto' => $item['nombre_producto'] ?? $producto->nombre,
                    'cantidad'        => $item['cantidad'],
                    'precio_unitario' => $item['precio_unitario'],
                    'exento'          => $producto->exento,
                    'subtotal'        => $sub,
                    'iva'             => $iva,
                    'total'           => $sub + $iva,
                    'motivo_item'     => $item['motivo_item'] ?? null,
                ];
            }

            $devolucion = VentaDevolucion::create([
                'orden_id'   => $data['orden_id'] ?? null,
                'cliente_id' => $data['cliente_id'],
                'tipo'       => $data['tipo'],
                'estado'     => 'pendiente',
                'motivo'     => $data['motivo'] ?? null,
                'notas'      => $data['notas'] ?? null,
                'subtotal'   => $subtotal,
                'total_iva'  => $totalIva,
                'total'      => $subtotal + $totalIva,
                'creado_por' => $data['creado_por'] ?? null,
            ]);

            foreach ($itemsData as $item) {
                $item['devolucion_id'] = $devolucion->id;
                VentaDevolucionItem::create($item);
            }
        });

        return response()->json($this->formatDetalle($devolucion->fresh(['cliente', 'orden', 'items.producto'])), 201);
    }

    public function update(Request $request, $id)
    {
        $dev = VentaDevolucion::findOrFail($id);

        $data = $request->validate([
            'estado'        => 'required|in:pendiente,aprobada,rechazada',
            'aprobado_por'  => 'nullable|string',
            'nota_resolucion' => 'nullable|string',
        ]);

        $dev->estado       = $data['estado'];
        $dev->aprobado_por = $data['aprobado_por'] ?? null;
        $dev->notas        = $data['nota_resolucion'] ?? $dev->notas;

        if (in_array($data['estado'], ['aprobada', 'rechazada'])) {
            $dev->aprobado_at = now();
        }

        $dev->save();

        return response()->json($this->format($dev->fresh('cliente')));
    }

    private function format(VentaDevolucion $d): array
    {
        return [
            'id'          => $d->id,
            'orden_id'    => $d->orden_id,
            'cliente'     => $d->cliente ? ['id' => $d->cliente->id, 'nombres' => $d->cliente->nombres, 'nom_comercial' => $d->cliente->nom_comercial] : null,
            'tipo'        => $d->tipo,
            'estado'      => $d->estado,
            'motivo'      => $d->motivo,
            'notas'       => $d->notas,
            'subtotal'    => $d->subtotal,
            'total_iva'   => $d->total_iva,
            'total'       => $d->total,
            'creado_por'  => $d->creado_por,
            'aprobado_por'=> $d->aprobado_por,
            'aprobado_at' => $d->aprobado_at?->toDateTimeString(),
            'created_at'  => $d->created_at?->toDateTimeString(),
        ];
    }

    private function formatDetalle(VentaDevolucion $d): array
    {
        $base = $this->format($d);
        $base['orden'] = $d->orden ? ['id' => $d->orden->id, 'total' => $d->orden->total, 'estado' => $d->orden->estado] : null;
        $base['items'] = $d->items->map(fn($i) => [
            'id'              => $i->id,
            'producto_id'     => $i->producto_id,
            'nombre_producto' => $i->nombre_producto,
            'cantidad'        => $i->cantidad,
            'precio_unitario' => $i->precio_unitario,
            'exento'          => $i->exento,
            'subtotal'        => $i->subtotal,
            'iva'             => $i->iva,
            'total'           => $i->total,
            'motivo_item'     => $i->motivo_item,
        ])->values();
        return $base;
    }
}
