<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaOrden;
use App\Models\Ventas\VentaOrdenItem;
use App\Models\Ventas\VentaAprobacion;
use App\Models\Ventas\VentaCliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrdenesController extends Controller
{
    public function index(Request $request)
    {
        $q = VentaOrden::with('cliente')->latest();

        if ($request->filled('estado')) {
            $q->where('estado', $request->estado);
        }

        if ($request->filled('cliente_id')) {
            $q->where('cliente_id', $request->cliente_id);
        }

        $ordenes = $q->paginate(30);
        $ordenes->getCollection()->transform(fn($o) => $this->format($o));

        return response()->json($ordenes);
    }

    public function show($id)
    {
        $orden = VentaOrden::with(['cliente', 'items.producto'])->findOrFail($id);
        return response()->json($this->formatDetalle($orden));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'cliente_id'       => 'required|integer',
            'tipo_venta'       => 'required|in:contado,credito',
            'plazo_solicitado' => 'integer|min:0',
            'notas'            => 'nullable|string',
            'creado_por'       => 'nullable|string',
            'items'            => 'required|array|min:1',
            'items.*.producto_id'    => 'required|integer',
            'items.*.cantidad'       => 'required|numeric|min:0.01',
            'items.*.precio_unitario'=> 'required|numeric|min:0',
        ]);

        DB::connection('compras')->transaction(function () use ($data, $request, &$orden) {
            $subtotal = 0;
            $totalIva = 0;

            $itemsData = [];
            foreach ($data['items'] as $item) {
                $producto = \App\Models\Ventas\VentaProducto::findOrFail($item['producto_id']);
                $sub = round($item['cantidad'] * $item['precio_unitario'], 2);
                $iva = $producto->exento ? 0 : round($sub * 0.13, 2);
                $subtotal += $sub;
                $totalIva += $iva;
                $itemsData[] = [
                    'producto_id'     => $item['producto_id'],
                    'nombre_producto' => $producto->nombre,
                    'cantidad'        => $item['cantidad'],
                    'precio_unitario' => $item['precio_unitario'],
                    'exento'          => $producto->exento,
                    'subtotal'        => $sub,
                    'iva'             => $iva,
                    'total'           => $sub + $iva,
                ];
            }

            $orden = VentaOrden::create([
                'cliente_id'       => $data['cliente_id'],
                'tipo_venta'       => $data['tipo_venta'],
                'plazo_solicitado' => $data['plazo_solicitado'] ?? 0,
                'estado'           => 'borrador',
                'subtotal'         => $subtotal,
                'total_iva'        => $totalIva,
                'total'            => $subtotal + $totalIva,
                'notas'            => $data['notas'] ?? null,
                'creado_por'       => $data['creado_por'] ?? null,
            ]);

            foreach ($itemsData as $item) {
                $item['orden_id'] = $orden->id;
                VentaOrdenItem::create($item);
            }

            // Si excede límite de crédito → crear solicitud de aprobación automáticamente
            $cliente = VentaCliente::find($data['cliente_id']);
            if ($data['tipo_venta'] === 'credito' && $cliente && $cliente->limite_credito > 0) {
                $totalPendiente = VentaOrden::where('cliente_id', $cliente->id)
                    ->whereIn('estado', ['aprobada', 'borrador', 'pendiente_aprobacion'])
                    ->sum('total');

                if ($totalPendiente > $cliente->limite_credito) {
                    VentaAprobacion::create([
                        'tipo'          => 'exceso_limite',
                        'orden_id'      => $orden->id,
                        'cliente_id'    => $cliente->id,
                        'detalle'       => "Orden #{$orden->id} excede límite de crédito. Límite: \${$cliente->limite_credito}, Total acumulado: \${$totalPendiente}",
                        'estado'        => 'pendiente',
                        'solicitado_por'=> $data['creado_por'] ?? null,
                    ]);
                    $orden->update(['estado' => 'pendiente_aprobacion']);
                }
            }
        });

        return response()->json($this->formatDetalle($orden->fresh(['cliente', 'items.producto'])), 201);
    }

    public function update(Request $request, $id)
    {
        $orden = VentaOrden::findOrFail($id);

        $data = $request->validate([
            'estado'        => 'sometimes|in:borrador,pendiente_aprobacion,aprobada,rechazada,completada',
            'notas'         => 'nullable|string',
            'aprobado_por'  => 'nullable|string',
        ]);

        if (isset($data['estado']) && in_array($data['estado'], ['aprobada', 'rechazada'])) {
            $data['aprobado_at'] = now();
        }

        $orden->update($data);
        return response()->json($this->format($orden->fresh('cliente')));
    }

    private function format(VentaOrden $o): array
    {
        return [
            'id'               => $o->id,
            'cliente'          => $o->cliente ? ['id' => $o->cliente->id, 'nombres' => $o->cliente->nombres, 'nom_comercial' => $o->cliente->nom_comercial] : null,
            'tipo_venta'       => $o->tipo_venta,
            'plazo_solicitado' => $o->plazo_solicitado,
            'estado'           => $o->estado,
            'subtotal'         => $o->subtotal,
            'total_iva'        => $o->total_iva,
            'total'            => $o->total,
            'notas'            => $o->notas,
            'creado_por'       => $o->creado_por,
            'created_at'       => $o->created_at?->toDateTimeString(),
        ];
    }

    private function formatDetalle(VentaOrden $o): array
    {
        $base = $this->format($o);
        $base['items'] = $o->items->map(fn($i) => [
            'id'              => $i->id,
            'producto_id'     => $i->producto_id,
            'nombre_producto' => $i->nombre_producto,
            'cantidad'        => $i->cantidad,
            'precio_unitario' => $i->precio_unitario,
            'exento'          => $i->exento,
            'subtotal'        => $i->subtotal,
            'iva'             => $i->iva,
            'total'           => $i->total,
        ])->values();
        return $base;
    }
}
