<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Services\Compras\ConsumoInventarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventarioController extends Controller
{
    public function __construct(private ConsumoInventarioService $consumoService) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/inventario
    // Lista el inventario actual de la sucursal con stock calculado
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $sucursalId = (int) $request->query('sucursal_id', 0);
        if (!$sucursalId) {
            return response()->json(['success' => false, 'message' => 'sucursal_id requerido.'], 422);
        }

        $inventarios = Inventario::where('sucursal_id', $sucursalId)
            ->with('producto')
            ->get();

        if ($inventarios->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $productoIds = $inventarios->pluck('producto_id')->all();

        // Sumar movimientos por producto
        $movimientos = DB::connection('compras')
            ->table('movimientos_inventario')
            ->where('sucursal_id', $sucursalId)
            ->whereIn('producto_id', $productoIds)
            ->selectRaw('producto_id, SUM(cantidad_base) as total_base')
            ->groupBy('producto_id')
            ->pluck('total_base', 'producto_id');

        $data = $inventarios->map(function ($inv) use ($movimientos) {
            $factor         = max((float) ($inv->producto?->factor_conversion ?? 1), 0.0001);
            $movBase        = (float) ($movimientos[$inv->producto_id] ?? 0);
            $stockActualBase = $inv->cantidad_inicial_base + $movBase;
            $stockActual    = $stockActualBase / $factor;

            $alerta = null;
            if ($inv->stock_minimo !== null) {
                if ($stockActual <= 0)              $alerta = 'agotado';
                elseif ($stockActual < $inv->stock_minimo) $alerta = 'bajo';
            }

            return [
                'id'                  => $inv->id,
                'producto_id'         => $inv->producto_id,
                'producto_nombre'     => $inv->producto?->nombre,
                'producto_codigo'     => $inv->producto?->codigo,
                'unidad'              => $inv->unidad,
                'unidad_base'         => $inv->producto?->unidad_base,
                'factor_conversion'   => $factor,
                'fecha_conteo'        => $inv->fecha_conteo?->toDateString(),
                'cantidad_inicial'    => $inv->cantidad_inicial,
                'stock_minimo'        => $inv->stock_minimo,
                'movimientos_base'    => round($movBase / $factor, 4),
                'stock_actual'        => round($stockActual, 4),
                'stock_actual_base'   => round($stockActualBase, 6),
                'alerta'              => $alerta,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/compras/inventario/cargar
    // Carga/reemplaza el inventario inicial (manual o desde Excel en el futuro)
    // Body: { sucursal_id, fecha_conteo, items: [{producto_id, cantidad, unidad, stock_minimo?}] }
    // ─────────────────────────────────────────────────────────────────────────
    public function cargar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sucursal_id'        => 'required|integer',
            'fecha_conteo'       => 'required|date',
            'items'              => 'required|array|min:1',
            'items.*.producto_id'=> 'required|integer',
            'items.*.cantidad'   => 'required|numeric|min:0',
            'items.*.unidad'     => 'required|string|max:30',
            'items.*.stock_minimo'=> 'nullable|numeric|min:0',
        ]);

        $sucursalId  = (int) $validated['sucursal_id'];
        $fechaConteo = $validated['fecha_conteo'];
        $usuario     = Auth::user()->email;

        // Obtener factores de conversión de los productos
        $productoIds = collect($validated['items'])->pluck('producto_id')->unique()->all();
        $productos   = DB::connection('compras')
            ->table('productos')
            ->whereIn('id', $productoIds)
            ->select('id', 'unidad_base', 'factor_conversion')
            ->get()
            ->keyBy('id');

        DB::connection('compras')->beginTransaction();
        try {
            foreach ($validated['items'] as $item) {
                $pid     = (int) $item['producto_id'];
                $prod    = $productos[$pid] ?? null;
                $factor  = max((float) ($prod?->factor_conversion ?? 1), 0.0001);
                $cantBase = (float) $item['cantidad'] * $factor;

                // Upsert del inventario base
                Inventario::updateOrCreate(
                    ['sucursal_id' => $sucursalId, 'producto_id' => $pid],
                    [
                        'cantidad_inicial'      => (float) $item['cantidad'],
                        'unidad'                => $item['unidad'],
                        'cantidad_inicial_base' => $cantBase,
                        'fecha_conteo'          => $fechaConteo,
                        'stock_minimo'          => isset($item['stock_minimo']) ? (float) $item['stock_minimo'] : null,
                        'aud_usuario'           => $usuario,
                    ]
                );

                // Eliminar movimientos anteriores de tipo carga_inicial para este producto/sucursal
                MovimientoInventario::where('sucursal_id', $sucursalId)
                    ->where('producto_id', $pid)
                    ->where('tipo', 'carga_inicial')
                    ->delete();

                // Registrar movimiento de carga inicial
                MovimientoInventario::create([
                    'sucursal_id'     => $sucursalId,
                    'producto_id'     => $pid,
                    'tipo'            => 'carga_inicial',
                    'cantidad'        => (float) $item['cantidad'],
                    'unidad'          => $item['unidad'],
                    'cantidad_base'   => $cantBase,
                    'motivo'          => 'Carga inicial de inventario — ' . $fechaConteo,
                    'fecha'           => $fechaConteo,
                    'referencia_tipo' => 'manual',
                    'aud_usuario'     => $usuario,
                ]);
            }

            DB::connection('compras')->commit();
        } catch (\Throwable $e) {
            DB::connection('compras')->rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => true, 'message' => count($validated['items']) . ' productos cargados.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/compras/inventario/ajuste
    // Registra un ajuste manual (merma, corrección positiva o negativa)
    // Body: { sucursal_id, producto_id, tipo: merma|ajuste, cantidad, unidad, motivo, fecha }
    // ─────────────────────────────────────────────────────────────────────────
    public function ajuste(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sucursal_id' => 'required|integer',
            'producto_id' => 'required|integer',
            'tipo'        => 'required|in:merma,ajuste',
            'cantidad'    => 'required|numeric',  // positivo o negativo
            'unidad'      => 'required|string|max:30',
            'motivo'      => 'required|string|max:500',
            'fecha'       => 'required|date',
        ]);

        $prod = DB::connection('compras')
            ->table('productos')
            ->where('id', $validated['producto_id'])
            ->select('factor_conversion', 'unidad_base')
            ->first();

        $factor   = max((float) ($prod?->factor_conversion ?? 1), 0.0001);
        $cantBase = (float) $validated['cantidad'] * $factor;

        MovimientoInventario::create([
            'sucursal_id'     => (int) $validated['sucursal_id'],
            'producto_id'     => (int) $validated['producto_id'],
            'tipo'            => $validated['tipo'],
            'cantidad'        => (float) $validated['cantidad'],
            'unidad'          => $validated['unidad'],
            'cantidad_base'   => $cantBase,
            'motivo'          => $validated['motivo'],
            'fecha'           => $validated['fecha'],
            'referencia_tipo' => 'manual',
            'aud_usuario'     => Auth::user()->email,
        ]);

        return response()->json(['success' => true, 'message' => 'Ajuste registrado.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/compras/inventario/{id}/stock-minimo
    // Actualiza el stock mínimo de alerta de un producto
    // ─────────────────────────────────────────────────────────────────────────
    public function actualizarStockMinimo(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'stock_minimo' => 'required|numeric|min:0',
        ]);

        $inv = Inventario::findOrFail($id);
        $inv->update(['stock_minimo' => $validated['stock_minimo'], 'aud_usuario' => Auth::user()->email]);

        return response()->json(['success' => true, 'data' => $inv]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/inventario/consumo
    // Calcula consumo de materias primas en el período (basado en ventas + recetas)
    // Query: sucursal_id, fecha_desde, fecha_hasta
    // ─────────────────────────────────────────────────────────────────────────
    public function consumo(Request $request): JsonResponse
    {
        $request->validate([
            'sucursal_id' => 'required|integer',
            'fecha_desde' => 'required|date',
            'fecha_hasta' => 'required|date|after_or_equal:fecha_desde',
        ]);

        $sucursalId = (int) $request->query('sucursal_id');
        $fechaDesde = $request->query('fecha_desde');
        $fechaHasta = $request->query('fecha_hasta');

        $consumo = $this->consumoService->calcular($sucursalId, $fechaDesde, $fechaHasta);

        return response()->json(['success' => true, 'data' => $consumo]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/compras/inventario/aplicar-consumo
    // Aplica el consumo calculado del período como movimientos en el inventario
    // Body: { sucursal_id, fecha_desde, fecha_hasta }
    // ─────────────────────────────────────────────────────────────────────────
    public function aplicarConsumo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sucursal_id' => 'required|integer',
            'fecha_desde' => 'required|date',
            'fecha_hasta' => 'required|date|after_or_equal:fecha_desde',
        ]);

        $sucursalId = (int) $validated['sucursal_id'];
        $fechaDesde = $validated['fecha_desde'];
        $fechaHasta = $validated['fecha_hasta'];
        $usuario    = Auth::user()->email;

        $consumo = $this->consumoService->calcular($sucursalId, $fechaDesde, $fechaHasta);

        if (empty($consumo)) {
            return response()->json(['success' => false, 'message' => 'No hay ventas con recetas mapeadas en ese período.'], 422);
        }

        // Eliminar consumos previos del mismo período para evitar duplicados
        MovimientoInventario::where('sucursal_id', $sucursalId)
            ->where('tipo', 'consumo')
            ->whereBetween('fecha', [$fechaDesde, $fechaHasta])
            ->delete();

        foreach ($consumo as $c) {
            $factor = max($c['factor_conversion'], 0.0001);
            MovimientoInventario::create([
                'sucursal_id'     => $sucursalId,
                'producto_id'     => $c['producto_id'],
                'tipo'            => 'consumo',
                'cantidad'        => -round($c['cantidad_base'] / $factor, 4),
                'unidad'          => $c['unidad_compra'],
                'cantidad_base'   => -$c['cantidad_base'],
                'motivo'          => "Consumo calculado {$fechaDesde} → {$fechaHasta}",
                'fecha'           => $fechaHasta,
                'referencia_tipo' => 'venta_semanal',
                'aud_usuario'     => $usuario,
            ]);
        }

        return response()->json([
            'success'   => true,
            'message'   => count($consumo) . ' productos descontados del inventario.',
            'aplicados' => count($consumo),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/inventario/movimientos
    // Historial de movimientos de la sucursal
    // Query: sucursal_id, producto_id (opcional), fecha_desde, fecha_hasta
    // ─────────────────────────────────────────────────────────────────────────
    public function movimientos(Request $request): JsonResponse
    {
        $request->validate([
            'sucursal_id' => 'required|integer',
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date',
        ]);

        $query = MovimientoInventario::where('sucursal_id', (int) $request->query('sucursal_id'))
            ->with('producto');

        if ($pid = $request->query('producto_id')) {
            $query->where('producto_id', (int) $pid);
        }
        if ($desde = $request->query('fecha_desde')) {
            $query->where('fecha', '>=', $desde);
        }
        if ($hasta = $request->query('fecha_hasta')) {
            $query->where('fecha', '<=', $hasta);
        }

        $movs = $query->orderByDesc('fecha')->orderByDesc('id')->limit(500)->get();

        $data = $movs->map(fn($m) => [
            'id'             => $m->id,
            'producto_id'    => $m->producto_id,
            'producto_nombre'=> $m->producto?->nombre,
            'tipo'           => $m->tipo,
            'cantidad'       => $m->cantidad,
            'unidad'         => $m->unidad,
            'motivo'         => $m->motivo,
            'fecha'          => $m->fecha?->toDateString(),
            'aud_usuario'    => $m->aud_usuario,
        ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/inventario/pedido-sugerido
    // Productos con stock bajo/agotado → candidatos para el pedido semanal
    // Query: sucursal_id
    // ─────────────────────────────────────────────────────────────────────────
    public function pedidoSugerido(Request $request): JsonResponse
    {
        $request->validate(['sucursal_id' => 'required|integer']);
        $sucursalId = (int) $request->query('sucursal_id');

        // Reusar lógica de index para obtener stock actual
        $indexResponse = $this->index(new Request(['sucursal_id' => $sucursalId]));
        $inventario    = json_decode($indexResponse->content(), true)['data'] ?? [];

        $sugeridos = collect($inventario)
            ->filter(fn($item) => in_array($item['alerta'], ['bajo', 'agotado']))
            ->map(function ($item) {
                $faltante = max(($item['stock_minimo'] ?? 0) - $item['stock_actual'], 0);
                return [
                    'producto_id'     => $item['producto_id'],
                    'producto_nombre' => $item['producto_nombre'],
                    'producto_codigo' => $item['producto_codigo'],
                    'unidad'          => $item['unidad'],
                    'stock_actual'    => $item['stock_actual'],
                    'stock_minimo'    => $item['stock_minimo'],
                    'cantidad_sugerida' => round(max($faltante, $item['stock_minimo'] ?? 1), 2),
                    'alerta'          => $item['alerta'],
                ];
            })
            ->values();

        return response()->json(['success' => true, 'data' => $sugeridos]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/compras/inventario/agregar-al-pedido
    // Agrega los productos sugeridos al pedido semanal activo (BORRADOR)
    // Body: { sucursal_id, semana_inicio, items: [{producto_id, cantidad}] }
    // ─────────────────────────────────────────────────────────────────────────
    public function agregarAlPedido(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sucursal_id'         => 'required|integer',
            'semana_inicio'       => 'required|date',
            'items'               => 'required|array|min:1',
            'items.*.producto_id' => 'required|integer',
            'items.*.cantidad'    => 'required|numeric|min:0.01',
        ]);

        $pedido = Pedido::firstOrCreate(
            ['sucursal_id' => $validated['sucursal_id'], 'semana_inicio' => $validated['semana_inicio']],
            ['estado' => 'BORRADOR', 'aud_usuario' => Auth::user()->email]
        );

        if ($pedido->estado === 'ENVIADO') {
            return response()->json(['success' => false, 'message' => 'El pedido de esa semana ya fue enviado.'], 422);
        }

        foreach ($validated['items'] as $item) {
            PedidoDetalle::updateOrCreate(
                ['pedido_id' => $pedido->id, 'producto_id' => $item['producto_id']],
                ['cantidad' => $item['cantidad'], 'nota' => 'Sugerido por inventario']
            );
        }

        return response()->json([
            'success'   => true,
            'message'   => count($validated['items']) . ' productos agregados al pedido.',
            'pedido_id' => $pedido->id,
        ]);
    }
}
