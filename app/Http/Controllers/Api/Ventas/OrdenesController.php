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
        $today = now()->toDateString();

        $data = $request->validate([
            'cliente_id'         => 'required|integer',
            'tipo_venta'         => 'required|in:contado,credito',
            'tipo_orden'         => 'nullable|in:normal,autoconsumo',
            'sub_ceco'           => 'nullable|string|max:100',
            'plazo_solicitado'   => 'integer|min:0',
            'notas'              => 'nullable|string',
            'creado_por'         => 'nullable|string',
            'tipo_aprobacion'    => 'nullable|string',
            'fecha_facturacion'  => "nullable|date|after_or_equal:{$today}",
            'fecha_entrega'      => "nullable|date|after_or_equal:{$today}",
            'items'              => 'required|array|min:1',
            'items.*.producto_id'    => 'required|integer',
            'items.*.cantidad'       => 'required|numeric|min:0.01',
            'items.*.precio_unitario'=> 'required|numeric|min:0',
        ]);

        $esAutoconsumo = ($data['tipo_orden'] ?? 'normal') === 'autoconsumo';

        DB::connection('compras')->transaction(function () use ($data, $today, $esAutoconsumo, &$orden) {
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

            $totalOrden = $subtotal + $totalIva;
            $cliente    = VentaCliente::findOrFail($data['cliente_id']);
            $clienteTieneCredito = $cliente->limite_credito > 0;

            // ── Determinar estado y si necesita aprobación ────────────────────
            $estado           = 'aprobada';
            $tipoAprobacion   = null;
            $detalleAprobacion = null;

            // Autoconsumo → siempre aprobado, sin flujo de crédito
            if ($esAutoconsumo) {
                $estado = 'aprobada';
            } elseif (isset($data['tipo_aprobacion']) && $data['tipo_aprobacion'] === 'cambio_precio') {
                // Vendedor modificó precio de catálogo → aprobación de jefe
                $estado            = 'pendiente_aprobacion';
                $tipoAprobacion    = 'cambio_precio';
                $detalleAprobacion = 'Vendedor modificó precios respecto al catálogo asignado al cliente.';
            } elseif ($data['tipo_venta'] === 'credito') {
                if (!$clienteTieneCredito) {
                    // Cliente contado solicitando crédito
                    $estado          = 'pendiente_aprobacion';
                    $tipoAprobacion  = 'cambio_credito';
                    $detalleAprobacion = "Cliente contado solicita venta a crédito.";
                } else {
                    // Cliente con crédito — verificar límite y plazo
                    $totalAcumulado = VentaOrden::where('cliente_id', $cliente->id)
                        ->whereIn('estado', ['aprobada', 'pendiente_aprobacion'])
                        ->sum('total');

                    if (($totalAcumulado + $totalOrden) > $cliente->limite_credito) {
                        $estado          = 'pendiente_aprobacion';
                        $tipoAprobacion  = 'exceso_limite';
                        $detalleAprobacion = "Orden excede límite de crédito de \${$cliente->limite_credito}. Acumulado: \$" . round($totalAcumulado + $totalOrden, 2) . ".";
                    } elseif ($cliente->plazo_credito > 0 && ($data['plazo_solicitado'] ?? 0) > $cliente->plazo_credito) {
                        $estado          = 'pendiente_aprobacion';
                        $tipoAprobacion  = 'cambio_credito';
                        $detalleAprobacion = "Vendedor solicita plazo de {$data['plazo_solicitado']} días. Estándar del cliente: {$cliente->plazo_credito} días.";
                    }
                }
            }
            // contado (cualquier cliente) → aprobada directamente

            $orden = VentaOrden::create([
                'cliente_id'        => $data['cliente_id'],
                'tipo_venta'        => $esAutoconsumo ? 'contado' : $data['tipo_venta'],
                'tipo_orden'        => $data['tipo_orden'] ?? 'normal',
                'sub_ceco'          => $data['sub_ceco'] ?? null,
                'plazo_solicitado'  => $esAutoconsumo ? 0 : ($data['plazo_solicitado'] ?? 0),
                'estado'            => $estado,
                'subtotal'          => $subtotal,
                'total_iva'         => $totalIva,
                'total'             => $totalOrden,
                'notas'             => $data['notas'] ?? null,
                'creado_por'        => $data['creado_por'] ?? null,
                'fecha_facturacion' => $data['fecha_facturacion'] ?? $today,
                'fecha_entrega'     => $data['fecha_entrega'] ?? $today,
                'aprobado_at'       => $estado === 'aprobada' ? now() : null,
                'aprobado_por'      => $estado === 'aprobada' ? 'Sistema' : null,
            ]);

            foreach ($itemsData as $item) {
                $item['orden_id'] = $orden->id;
                VentaOrdenItem::create($item);
            }

            if ($tipoAprobacion) {
                VentaAprobacion::create([
                    'tipo'           => $tipoAprobacion,
                    'orden_id'       => $orden->id,
                    'cliente_id'     => $cliente->id,
                    'detalle'        => $detalleAprobacion,
                    'estado'         => 'pendiente',
                    'solicitado_por' => $data['creado_por'] ?? null,
                ]);
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
            'id'                => $o->id,
            'cliente'           => $o->cliente ? ['id' => $o->cliente->id, 'nombres' => $o->cliente->nombres, 'nom_comercial' => $o->cliente->nom_comercial, 'limite_credito' => $o->cliente->limite_credito, 'plazo_credito' => $o->cliente->plazo_credito] : null,
            'tipo_venta'        => $o->tipo_venta,
            'tipo_orden'        => $o->tipo_orden ?? 'normal',
            'sub_ceco'          => $o->sub_ceco,
            'plazo_solicitado'  => $o->plazo_solicitado,
            'estado'            => $o->estado,
            'subtotal'          => $o->subtotal,
            'total_iva'         => $o->total_iva,
            'total'             => $o->total,
            'notas'             => $o->notas,
            'creado_por'        => $o->creado_por,
            'fecha_facturacion' => $o->fecha_facturacion?->toDateString(),
            'fecha_entrega'     => $o->fecha_entrega?->toDateString(),
            'created_at'        => $o->created_at?->toDateTimeString(),
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
