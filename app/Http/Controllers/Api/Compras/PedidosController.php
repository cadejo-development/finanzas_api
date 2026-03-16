<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\CentroCosto;
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

        $query = Pedido::withCount('detalles')->where('estado', 'ENVIADO');

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
     */
    public function miBorrador(Request $request): JsonResponse
    {
        $sucursalId   = (int) $request->query('sucursal_id');
        $semanaInicio = $request->query('semana_inicio');

        if (!$sucursalId || !$semanaInicio) {
            return response()->json(['success' => false, 'message' => 'sucursal_id y semana_inicio son requeridos'], 422);
        }

        try {
            // Busca cualquier pedido existente para esa semana (BORRADOR o ENVIADO)
            $pedido = Pedido::where('sucursal_id', $sucursalId)
                ->where('semana_inicio', $semanaInicio)
                ->orderByRaw("CASE WHEN estado = 'BORRADOR' THEN 0 ELSE 1 END")
                ->first();

            // Solo crea si no existe ninguno (evita unique violation por race condition)
            if (!$pedido) {
                $pedido = Pedido::firstOrCreate(
                    ['sucursal_id' => $sucursalId, 'semana_inicio' => $semanaInicio],
                    ['estado' => 'BORRADOR', 'total_estimado' => 0, 'aud_usuario' => auth('sanctum')->user()?->email ?? 'api']
                );
            }

            $pedido->load('detalles.producto');
            return response()->json(['success' => true, 'data' => $this->formatPedido($pedido)]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('miBorrador error', [
                'sucursal_id'   => $sucursalId,
                'semana_inicio' => $semanaInicio,
                'error'         => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/compras/pedidos/{id}/items
     */
    public function guardarItems(Request $request, int $id): JsonResponse
    {
        $pedido = Pedido::with('detalles.producto')->findOrFail($id);

        if ($pedido->estado !== 'BORRADOR') {
            return response()->json(['success' => false, 'message' => 'El pedido ya fue enviado'], 422);
        }

        $validated = $request->validate([
            'items'                   => 'required|array',
            'items.*.producto_id'     => 'required|integer',
            'items.*.cantidad'        => 'required|numeric|min:0',
            'items.*.precio_unitario' => 'required|numeric|min:0',
            'items.*.nota'            => 'nullable|string',
            'items.*.unidad'          => 'nullable|string|max:20',
        ]);

        DB::connection('compras')->transaction(function () use ($pedido, $validated) {
            $pedido->detalles()->delete();

            $total = 0;
            foreach ($validated['items'] as $item) {
                if ((float) $item['cantidad'] <= 0) continue;
                $subtotal = round((float) $item['cantidad'] * (float) $item['precio_unitario'], 2);
                $total   += $subtotal;

                PedidoDetalle::create([
                    'pedido_id'       => $pedido->id,
                    'producto_id'     => $item['producto_id'],
                    'cantidad'        => $item['cantidad'],
                    'unidad'          => $item['unidad'] ?? null,
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
     */
    public function enviar(int $id): JsonResponse
    {
        $pedido = Pedido::with('detalles.producto')->findOrFail($id);

        if ($pedido->estado === 'ENVIADO') {
            return response()->json(['success' => false, 'message' => 'El pedido ya fue enviado'], 422);
        }

        if ($pedido->detalles->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'El pedido esta vacio'], 422);
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
     * Devuelve consolidado por producto. Si hay sucursal_id filtra por ella y
     * agrega detalle por pedido (quién lo hizo, sucursal, items individuales).
     */
    public function consolidado(Request $request): JsonResponse
    {
        $semana     = $request->query('semana_inicio');
        $sucursalId = $request->query('sucursal_id');

        $query = Pedido::with('detalles.producto')->where('estado', 'ENVIADO');

        if ($semana)     $query->where('semana_inicio', $semana);
        if ($sucursalId) $query->where('sucursal_id', $sucursalId);

        $pedidos = $query->orderBy('sucursal_id')->get();

        // Cargar nombres y codigos de sucursales
        $sucursalIds = $pedidos->pluck('sucursal_id')->unique()->filter()->values()->toArray();
        $sucursales  = Sucursal::whereIn('id', $sucursalIds)->get()->keyBy('id');

        $map         = [];
        $pedidosMeta = [];

        foreach ($pedidos as $pedido) {
            $suc = $sucursales[$pedido->sucursal_id] ?? null;

            $pedidosMeta[$pedido->id] = [
                'pedido_id'       => $pedido->id,
                'sucursal_id'     => $pedido->sucursal_id,
                'sucursal_nombre' => $suc?->nombre ?? ('Sucursal ' . $pedido->sucursal_id),
                'sucursal_codigo' => $suc?->codigo ?? '',
                'semana_inicio'   => substr((string) $pedido->semana_inicio, 0, 10),
                'total_estimado'  => (float) $pedido->total_estimado,
                'aud_usuario'     => $pedido->aud_usuario ?? '',
                'num_items'       => $pedido->detalles->count(),
                'items'           => [],
            ];

            foreach ($pedido->detalles as $det) {
                $pid = $det->producto_id;

                if (!isset($map[$pid])) {
                    $map[$pid] = [
                        'producto_id'       => $pid,
                        'producto_codigo'   => $det->producto?->codigo ?? '',
                        'producto_nombre'   => $det->producto?->nombre ?? ('Producto ' . $pid),
                        'unidad'            => $det->unidad ?? $det->producto?->unidad ?? '',
                        'total_cantidad'    => 0.0,
                        'total_subtotal'    => 0.0,
                        'num_pedidos'       => 0,
                        'precio_promedio'   => 0.0,
                        '_precios'          => [],
                        'por_sucursal'      => [],
                    ];
                }

                $map[$pid]['total_cantidad'] += (float) $det->cantidad;
                $map[$pid]['total_subtotal'] += (float) $det->subtotal;
                $map[$pid]['num_pedidos']++;
                if ((float) $det->precio_unitario > 0) {
                    $map[$pid]['_precios'][] = (float) $det->precio_unitario;
                }

                // Detalle por sucursal
                $sucNombre = $suc?->nombre ?? ('Sucursal ' . $pedido->sucursal_id);
                $sucCodigo = $suc?->codigo ?? '';
                $keyS = $pedido->sucursal_id;
                if (!isset($map[$pid]['por_sucursal'][$keyS])) {
                    $map[$pid]['por_sucursal'][$keyS] = [
                        'sucursal_id'     => $pedido->sucursal_id,
                        'sucursal_nombre' => $sucNombre,
                        'sucursal_codigo' => $sucCodigo,
                        'gerente'         => $pedido->aud_usuario ?? '',
                        'cantidad'        => 0.0,
                        'subtotal'        => 0.0,
                    ];
                }
                $map[$pid]['por_sucursal'][$keyS]['cantidad'] += (float) $det->cantidad;
                $map[$pid]['por_sucursal'][$keyS]['subtotal'] += (float) $det->subtotal;
            }
        }

        $consolidado = array_values(array_map(function ($r) {
            $precios = $r['_precios'];
            $promedio = count($precios) ? round(array_sum($precios) / count($precios), 2) : 0;
            return [
                'producto_id'     => $r['producto_id'],
                'producto_codigo' => $r['producto_codigo'],
                'producto_nombre' => $r['producto_nombre'],
                'unidad'          => $r['unidad'],
                'total_cantidad'  => round($r['total_cantidad'], 2),
                'total_subtotal'  => round($r['total_subtotal'], 2),
                'num_pedidos'     => $r['num_pedidos'],
                'precio_promedio' => $promedio,
                'por_sucursal'    => array_values($r['por_sucursal']),
            ];
        }, $map));

        usort($consolidado, fn ($a, $b) => strcmp($a['producto_nombre'], $b['producto_nombre']));

        // Meta: pedidos individuales (útil para vista por sucursal)
        $pedidosDetalle = array_values($pedidosMeta);

        return response()->json([
            'success'  => true,
            'data'     => $consolidado,
            'pedidos'  => $pedidosDetalle,
            'semana'   => $semana,
            'total_productos'  => count($consolidado),
            'total_pedidos'    => count($pedidosDetalle),
        ]);
    }

    /**
     * GET /api/compras/pedidos/exportar-odc
     * Devuelve filas por ítem por pedido, con CECO y sucursal, para generar el CSV ODC.
     */
    public function exportarOdc(Request $request): JsonResponse
    {
        $semana     = $request->query('semana_inicio');
        $sucursalId = $request->query('sucursal_id');

        $pedidos = Pedido::with(['detalles.producto'])
            ->where('estado', 'ENVIADO')
            ->when($semana,     fn ($q) => $q->where('semana_inicio', $semana))
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->orderBy('sucursal_id')
            ->get();

        // Cargar sucursales (pgsql) con sus centros de costo
        $sucursalIds = $pedidos->pluck('sucursal_id')->unique()->filter()->values()->toArray();

        $sucursales = Sucursal::with(['centrosCosto' => fn ($q) => $q->where('activo', true)])
            ->whereIn('id', $sucursalIds)
            ->get()
            ->keyBy('id');

        $filas = [];

        foreach ($pedidos as $pedido) {
            $suc   = $sucursales[$pedido->sucursal_id] ?? null;
            $cecos = $suc?->centrosCosto ?? collect();

            // Agrupador (padre, es_sub=false) → CECO-XX
            // Sub      (hijo,  es_sub=true)  → CECO-XX-01
            $agrupador = $cecos->firstWhere('es_sub', false);
            $sub       = $cecos->firstWhere('es_sub', true);

            foreach ($pedido->detalles as $det) {
                $filas[] = [
                    'pedido_id'       => $pedido->id,
                    'semana_inicio'   => substr((string) $pedido->semana_inicio, 0, 10),
                    'sucursal_codigo' => $suc?->codigo ?? '',
                    'ceco'            => $agrupador?->codigo ?? '',
                    'sub_ceco'        => $sub?->codigo ?? '',
                    'producto_codigo' => $det->producto?->codigo ?? '',
                    'producto_nombre' => $det->producto?->nombre ?? '',
                    'unidad'          => $det->unidad ?? $det->producto?->unidad ?? '',
                    'cantidad'        => (float) $det->cantidad,
                    'precio_unitario' => (float) $det->precio_unitario,
                ];
            }
        }

        return response()->json(['success' => true, 'data' => $filas]);
    }

    // ── Helper ────────────────────────────────────────────────────────────────
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
                'unidad'          => $d->unidad ?? $d->producto?->unidad ?? '',
                'cantidad'        => (float) $d->cantidad,
                'precio_unitario' => (float) $d->precio_unitario,
                'subtotal'        => (float) $d->subtotal,
                'nota'            => $d->nota ?? '',
            ])->values(),
        ];
    }
}
