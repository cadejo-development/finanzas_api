<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Sucursal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PedidosController extends Controller
{
    /**
     * GET /api/compras/pedidos
     * Lista pedidos ENVIADOS.
     */
    public function index(Request $request): JsonResponse
    {
        $semana     = $request->query('semana_inicio');
        $sucursalId = $request->query('sucursal_id');

        $query = Pedido::withCount('detalles')
            ->where('estado', 'ENVIADO');

        if ($semana)     $query->where('semana_inicio', $semana);
        if ($sucursalId) $query->where('sucursal_id', $sucursalId);

        $pedidos = $query->orderByDesc('semana_inicio')->orderBy('sucursal_id')->get();

        $ids        = $pedidos->pluck('sucursal_id')->unique()->filter()->values()->toArray();
        $sucursales = Sucursal::whereIn('id', $ids)->pluck('nombre', 'id');

        $data = $pedidos->map(fn ($p) => [
            'id'              => $p->id,
            'sucursal_id'     => $p->sucursal_id,
            'sucursal_nombre' => $sucursales[$p->sucursal_id] ?? ('Sucursal ' . $p->sucursal_id),
            'semana_inicio'   => substr((string) $p->semana_inicio, 0, 10),
            'estado'          => $p->estado,
            'total'           => (float) $p->total_estimado,
            'num_items'       => $p->detalles_count,
        ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /api/compras/pedidos/semanas
     */
    public function semanas(): JsonResponse
    {
        try {
            $semanas = Pedido::where('estado', 'ENVIADO')
                ->distinct()
                ->orderByDesc('semana_inicio')
                ->pluck('semana_inicio')
                ->map(fn ($d) => substr((string) $d, 0, 10));

            return response()->json(['success' => true, 'data' => $semanas]);
        } catch (\Throwable $e) {
            return response()->json(['success' => true, 'data' => []]);
        }
    }

    /**
     * GET /api/compras/pedidos/mi-borrador?sucursal_id=X&semana_inicio=YYYY-MM-DD
     * Devuelve el borrador existente o crea uno vacío.
     */
    public function miBorrador(Request $request): JsonResponse
    {
        $sucursalId   = (int) $request->query('sucursal_id');
        $semanaInicio = $request->query('semana_inicio');

        if (!$sucursalId || !$semanaInicio) {
            return response()->json(['success' => false, 'message' => 'sucursal_id y semana_inicio son requeridos'], 422);
        }

        // Buscar borrador O enviado para esa semana+sucursal
        $pedido = Pedido::with('detalles.producto')
            ->where('sucursal_id', $sucursalId)
            ->where('semana_inicio', $semanaInicio)
            ->orderByRaw("CASE WHEN estado = 'BORRADOR' THEN 0 ELSE 1 END")
            ->first();

        if (!$pedido) {
            $pedido = Pedido::create([
                'sucursal_id'    => $sucursalId,
                'semana_inicio'  => $semanaInicio,
                'estado'         => 'BORRADOR',
                'total_estimado' => 0,
                'aud_usuario'    => auth('sanctum')->user()?->email ?? 'api',
            ]);
            $pedido->load('detalles.producto');
        }

        return response()->json(['success' => true, 'data' => $this->formatPedido($pedido)]);
    }

    /**
     * PUT /api/compras/pedidos/{id}/items
     * Reemplaza todos los items del pedido (solo si BORRADOR).
     * Body: { items: [{ producto_id, cantidad, precio_unitario, nota }] }
     */
    public function guardarItems(Request $request, int $id): JsonResponse
    {
        $pedido = Pedido::with('detalles.producto')->findOrFail($id);

        if ($pedido->estado !== 'BORRADOR') {
            return response()->json(['success' => false, 'message' => 'El pedido ya fue enviado'], 422);
        }

        $validated = $request->validate([
            'items'                    => 'required|array',
            'items.*.producto_id'      => 'required|integer',
            'items.*.cantidad'         => 'required|numeric|min:0',
            'items.*.precio_unitario'  => 'required|numeric|min:0',
            'items.*.nota'             => 'nullable|string',
        ]);

        DB::connection('compras')->transaction(function () use ($pedido, $validated) {
            $pedido->detalles()->delete();

            $total = 0;
            foreach ($validated['items'] as $item) {
                if ((float)$item['cantidad'] <= 0) continue;
                $subtotal = round((float)$item['cantidad'] * (float)$item['precio_unitario'], 2);
                $total   += $subtotal;

                PedidoDetalle::create([
                    'pedido_id'       => $pedido->id,
                    'producto_id'     => $item['producto_id'],
                    'cantidad'        => $item['cantidad'],
                    'precio_unitario' => $item['precio_unitario'],
                    'subtotal'        => $subtotal,
                    'nota'            => $item['nota'] ?? null,
                    'aud_usuario'     => auth('sanctum')->user()?->email ?? 'api',
                ]);
            }

            $pedido->update([
                'total_estimado' => round($total, 2),
                'aud_usuario'    => auth('sanctum')->user()?->email ?? 'api',
            ]);
        });

        $pedido->load('detalles.producto');
        return response()->json(['success' => true, 'data' => $this->formatPedido($pedido)]);
    }

    /**
     * POST /api/compras/pedidos/{id}/enviar
     * Cambia estado de BORRADOR a ENVIADO.
     */
    public function enviar(int $id): JsonResponse
    {
        $pedido = Pedido::with('detalles.producto')->findOrFail($id);

        if ($pedido->estado === 'ENVIADO') {
            return response()->json(['success' => false, 'message' => 'El pedido ya fue enviado'], 422);
        }

        if ($pedido->detalles->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'El pedido está vacío'], 422);
        }

        $pedido->update([
            'estado'      => 'ENVIADO',
            'aud_usuario' => auth('sanctum')->user()?->email ?? 'api',
        ]);

        $pedido->load('detalles.producto');
        return response()->json(['success' => true, 'data' => $this->formatPedido($pedido)]);
    }

    /**
     * GET /api/compras/pedidos/{id}
     */
    public function show(int $id): JsonResponse
    {
        $pedido   = Pedido::with('detalles.producto')->findOrFail($id);
        $sucursal = Sucursal::find($pedido->sucursal_id);

        return response()->json([
            'success' => true,
            'data'    => $this->formatPedido($pedido, $sucursal?->nombre),
        ]);
    }

    /**
     * GET /api/compras/pedidos/consolidado
     */
    public function consolidado(Request $request): JsonResponse
    {
        $semana     = $request->query('semana_inicio');
        $sucursalId = $request->query('sucursal_id');

        $query = Pedido::with('detalles.producto')->where('estado', 'ENVIADO');
        if ($semana)     $query->where('semana_inicio', $semana);
        if ($sucursalId) $query->where('sucursal_id', $sucursalId);

        $pedidos = $query->get();
        $map     = [];

        foreach ($pedidos as $pedido) {
            foreach ($pedido->detalles as $det) {
                $pid = $det->producto_id;
                if (!isset($map[$pid])) {
                    $map[$pid] = [
                        'producto_id'     => $pid,
                        'producto_nombre' => $det->producto?->nombre ?? ('Producto ' . $pid),
                        'total_cantidad'  => 0.0,
                        'total_subtotal'  => 0.0,
                    ];
                }
                $map[$pid]['total_cantidad'] += (float) $det->cantidad;
                $map[$pid]['total_subtotal'] += (float) $det->subtotal;
            }
        }

        $consolidado = array_values(array_map(fn ($r) => [
            ...$r,
            'total_cantidad' => round($r['total_cantidad'], 2),
            'total_subtotal' => round($r['total_subtotal'], 2),
        ], $map));

        usort($consolidado, fn ($a, $b) => strcmp($a['producto_nombre'], $b['producto_nombre']));

        return response()->json(['success' => true, 'data' => $consolidado]);
    }

    // ── Helper ─────────────────────────────────────────────────────────────
    private function formatPedido(Pedido $p, ?string $sucursalNombre = null): array
    {
        return [
            'id'              => $p->id,
            'sucursal_id'     => $p->sucursal_id,
            'sucursal_nombre' => $sucursalNombre ?? ('Sucursal ' . $p->sucursal_id),
            'semana_inicio'   => substr((string) $p->semana_inicio, 0, 10),
            'estado'          => $p->estado,
            'total'           => (float) $p->total_estimado,
            'items'           => $p->detalles->map(fn ($d) => [
                'id'              => $d->id,
                'producto_id'     => $d->producto_id,
                'producto_nombre' => $d->producto?->nombre ?? ('Producto ' . $d->producto_id),
                'cantidad'        => (float) $d->cantidad,
                'precio_unitario' => (float) $d->precio_unitario,
                'subtotal'        => (float) $d->subtotal,
                'nota'            => $d->nota ?? '',
            ])->values(),
        ];
    }
}

    /**
     * GET /api/compras/pedidos
     * Lista pedidos ENVIADOS.
     * Query params:
     *   - semana_inicio (YYYY-MM-DD) : filtra por semana
     *   - sucursal_id   (int)        : filtra por sucursal
     */
    public function index(Request $request): JsonResponse
    {
        $semana     = $request->query('semana_inicio');
        $sucursalId = $request->query('sucursal_id');

        $query = Pedido::withCount('detalles')
            ->where('estado', 'ENVIADO');

        if ($semana)     $query->where('semana_inicio', $semana);
        if ($sucursalId) $query->where('sucursal_id', $sucursalId);

        $pedidos = $query->orderByDesc('semana_inicio')->orderBy('sucursal_id')->get();

        // Cargar sucursales de pgsql y mapear por id
        $ids       = $pedidos->pluck('sucursal_id')->unique()->filter()->values()->toArray();
        $sucursales = Sucursal::whereIn('id', $ids)->pluck('nombre', 'id');

        $data = $pedidos->map(fn ($p) => [
            'id'              => $p->id,
            'sucursal_id'     => $p->sucursal_id,
            'sucursal_nombre' => $sucursales[$p->sucursal_id] ?? ('Sucursal ' . $p->sucursal_id),
            'semana_inicio'   => substr((string) $p->semana_inicio, 0, 10),
            'estado'          => $p->estado,
            'total'           => (float) $p->total_estimado,
            'num_items'       => $p->detalles_count,
        ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /api/compras/pedidos/semanas
     * Semanas distintas con pedidos ENVIADOS.
     */
    public function semanas(): JsonResponse
    {
        try {
            $semanas = Pedido::where('estado', 'ENVIADO')
                ->distinct()
                ->orderByDesc('semana_inicio')
                ->pluck('semana_inicio')
                ->map(fn ($d) => is_string($d) ? substr($d, 0, 10) : (string) \Illuminate\Support\Carbon::parse($d)->toDateString());

            return response()->json(['success' => true, 'data' => $semanas]);
        } catch (\Throwable $e) {
            return response()->json(['success' => true, 'data' => []]);
        }
    }

    /**
     * GET /api/compras/pedidos/{id}
     * Detalle completo de un pedido.
     */
    public function show(int $id): JsonResponse
    {
        $pedido = Pedido::with('detalles.producto')->findOrFail($id);

        $sucursal = Sucursal::find($pedido->sucursal_id);

        $detalles = $pedido->detalles->map(fn ($d) => [
            'id'              => $d->id,
            'producto_id'     => $d->producto_id,
            'producto_nombre' => $d->producto?->nombre ?? ('Producto ' . $d->producto_id),
            'cantidad'        => (float) $d->cantidad,
            'precio_unitario' => (float) $d->precio_unitario,
            'subtotal'        => (float) $d->subtotal,
            'nota'            => $d->nota,
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'              => $pedido->id,
                'sucursal_id'     => $pedido->sucursal_id,
                'sucursal_nombre' => $sucursal?->nombre ?? ('Sucursal ' . $pedido->sucursal_id),
                'semana_inicio'   => substr((string) $pedido->semana_inicio, 0, 10),
                'estado'          => $pedido->estado,
                'total'           => (float) $pedido->total_estimado,
                'detalles'        => $detalles,
            ],
        ]);
    }

    /**
     * GET /api/compras/pedidos/consolidado
     * Consolidado de productos por semana.
     * Query params:
     *   - semana_inicio (YYYY-MM-DD) : requerido
     *   - sucursal_id   (int)        : opcional — filtra por sucursal
     */
    public function consolidado(Request $request): JsonResponse
    {
        $semana     = $request->query('semana_inicio');
        $sucursalId = $request->query('sucursal_id');

        $query = Pedido::with('detalles.producto')
            ->where('estado', 'ENVIADO');

        if ($semana)     $query->where('semana_inicio', $semana);
        if ($sucursalId) $query->where('sucursal_id', $sucursalId);

        $pedidos = $query->get();

        // Agrupa por producto
        $map = [];
        foreach ($pedidos as $pedido) {
            foreach ($pedido->detalles as $det) {
                $pid = $det->producto_id;
                if (!isset($map[$pid])) {
                    $map[$pid] = [
                        'producto_id'    => $pid,
                        'producto_nombre'=> $det->producto?->nombre ?? ('Producto ' . $pid),
                        'total_cantidad' => 0.0,
                        'total_subtotal' => 0.0,
                    ];
                }
                $map[$pid]['total_cantidad'] += (float) $det->cantidad;
                $map[$pid]['total_subtotal'] += (float) $det->subtotal;
            }
        }

        $consolidado = array_values(array_map(fn ($r) => [
            ...$r,
            'total_cantidad' => round($r['total_cantidad'], 2),
            'total_subtotal' => round($r['total_subtotal'], 2),
        ], $map));

        // Ordena por nombre
        usort($consolidado, fn ($a, $b) => strcmp($a['producto_nombre'], $b['producto_nombre']));

        return response()->json(['success' => true, 'data' => $consolidado]);
    }
}
