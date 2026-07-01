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

        // ── 1. Productos ya en inventarios para esta sucursal ─────────────────
        $inventarios = Inventario::where('sucursal_id', $sucursalId)
            ->with(['producto', 'producto.categoria'])
            ->get()
            ->keyBy('producto_id');

        // ── 2. Todos los productos de recetas asignadas a esta sucursal ───────
        //      Resuelve sub-recetas recursivamente (cualquier profundidad).
        //      No filtra por activa ni por estado de menú.
        $recetaRows = DB::connection('compras')->select("
            WITH RECURSIVE ingredientes_flat AS (
                SELECT ri.producto_id, ri.sub_receta_id
                FROM receta_sucursal rs
                INNER JOIN receta_ingredientes ri ON ri.receta_id = rs.receta_id
                WHERE rs.sucursal_id = ?
                UNION
                SELECT ri.producto_id, ri.sub_receta_id
                FROM ingredientes_flat prev
                INNER JOIN receta_ingredientes ri ON ri.receta_id = prev.sub_receta_id
                WHERE prev.sub_receta_id IS NOT NULL
            )
            SELECT DISTINCT producto_id
            FROM ingredientes_flat
            WHERE producto_id IS NOT NULL
        ", [$sucursalId]);

        $recetaProductoIds = collect($recetaRows)->pluck('producto_id')->map(fn($id) => (int) $id)->all();

        // ── 3. Productos de recetas que todavía no están en inventarios ────────
        $inventarioIds = $inventarios->keys()->map(fn($id) => (int) $id)->all();
        $missingIds    = array_values(array_diff($recetaProductoIds, $inventarioIds));

        $missingProductos = collect();
        if ($missingIds) {
            $missingProductos = DB::connection('compras')
                ->table('productos as p')
                ->leftJoin('categorias as c', 'c.id', '=', 'p.categoria_id')
                ->whereIn('p.id', $missingIds)
                ->select('p.*', 'c.nombre as categoria_nombre_cat')
                ->get()
                ->keyBy('id');
        }

        // ── 4. Movimientos para todos los productos ───────────────────────────
        $allIds = array_unique(array_merge($inventarioIds, $recetaProductoIds));

        if (empty($allIds)) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $movimientos = DB::connection('compras')
            ->table('movimientos_inventario')
            ->where('sucursal_id', $sucursalId)
            ->whereIn('producto_id', $allIds)
            ->where('tipo', '!=', 'carga_inicial')
            ->selectRaw('producto_id, SUM(cantidad_base) as total_base')
            ->groupBy('producto_id')
            ->pluck('total_base', 'producto_id');

        $data = collect();

        // ── 5a. Productos con entrada en inventarios (comportamiento existente) ─
        foreach ($inventarios as $inv) {
            $factor          = max((float) ($inv->producto?->factor_conversion ?? 1), 0.0001);
            $movBase         = (float) ($movimientos[$inv->producto_id] ?? 0);
            $stockActualBase = $inv->cantidad_inicial_base + $movBase;
            $stockActual     = $stockActualBase / $factor;

            $alerta = null;
            if ($inv->stock_minimo !== null) {
                if ($stockActual <= 0)                     $alerta = 'agotado';
                elseif ($stockActual < $inv->stock_minimo) $alerta = 'bajo';
            }

            $data->push([
                'id'                  => $inv->id,
                'producto_id'         => $inv->producto_id,
                'producto_nombre'     => $inv->producto?->nombre,
                'producto_codigo'     => $inv->producto?->codigo,
                'unidad'              => $inv->unidad ?: $inv->producto?->unidad,
                'unidad_base'         => $inv->producto?->unidad_base,
                'factor_conversion'   => $factor,
                'fecha_conteo'        => $inv->fecha_conteo?->toDateString(),
                'cantidad_inicial'    => $inv->cantidad_inicial,
                'stock_minimo'        => $inv->stock_minimo,
                'seccion'             => $inv->seccion,
                'brilo_stock'         => $inv->brilo_stock !== null ? (float) $inv->brilo_stock : null,
                'brilo_sync_at'       => $inv->brilo_sync_at?->toDateTimeString(),
                'costo'               => (float) ($inv->producto?->costo ?? 0),
                'categoria_id'        => $inv->producto?->categoria_id,
                'categoria_nombre'    => $inv->producto?->categoria?->nombre,
                'unidad_compra'       => $inv->producto?->unidad_compra,
                'unidad_compra_nombre'=> $inv->producto?->unidad_compra_nombre,
                'factor_unidad_compra'=> $inv->producto?->factor_unidad_compra
                                          ? (float) $inv->producto->factor_unidad_compra : null,
                'movimientos_base'    => round($movBase / $factor, 4),
                'stock_actual'        => round($stockActual, 4),
                'stock_actual_base'   => round($stockActualBase, 6),
                'alerta'              => $alerta,
                'activo'              => (bool) ($inv->activo ?? true),
            ]);
        }

        // ── 5b. Productos de recetas sin entrada en inventarios (stock = 0) ────
        foreach ($missingProductos as $prod) {
            $factor          = max((float) ($prod->factor_conversion ?? 1), 0.0001);
            $movBase         = (float) ($movimientos[$prod->id] ?? 0);
            $stockActualBase = $movBase;
            $stockActual     = $stockActualBase / $factor;

            $data->push([
                'id'                  => null,
                'producto_id'         => (int) $prod->id,
                'producto_nombre'     => $prod->nombre,
                'producto_codigo'     => $prod->codigo,
                'unidad'              => $prod->unidad,
                'unidad_base'         => $prod->unidad_base,
                'factor_conversion'   => $factor,
                'fecha_conteo'        => null,
                'cantidad_inicial'    => null,
                'stock_minimo'        => null,
                'seccion'             => null,
                'brilo_stock'         => null,
                'brilo_sync_at'       => null,
                'costo'               => (float) ($prod->costo ?? 0),
                'categoria_id'        => $prod->categoria_id,
                'categoria_nombre'    => $prod->categoria_nombre_cat,
                'unidad_compra'       => $prod->unidad_compra,
                'unidad_compra_nombre'=> $prod->unidad_compra_nombre,
                'factor_unidad_compra'=> $prod->factor_unidad_compra
                                          ? (float) $prod->factor_unidad_compra : null,
                'movimientos_base'    => round($movBase / $factor, 4),
                'stock_actual'        => round($stockActual, 4),
                'stock_actual_base'   => round($stockActualBase, 6),
                'alerta'              => $stockActual <= 0 ? 'agotado' : null,
            ]);
        }

        return response()->json(['success' => true, 'data' => $data->values()]);
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
    // POST /api/compras/inventario/secciones-masivas
    // Asigna secciones por categoría de producto (bulk)
    // Body: { sucursal_id, asignaciones: [{categoria_id, seccion}] }
    // ─────────────────────────────────────────────────────────────────────────
    public function asignarSeccionesMasivas(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sucursal_id'                => 'required|integer',
            'asignaciones'               => 'required|array|min:1',
            'asignaciones.*.categoria_id'=> 'required|integer',
            'asignaciones.*.seccion'     => 'nullable|string|max:50',
        ]);

        $sucursalId = (int) $validated['sucursal_id'];
        $usuario    = Auth::user()->email;
        $total      = 0;

        foreach ($validated['asignaciones'] as $asig) {
            $subIds = DB::connection('compras')
                ->table('productos')
                ->where('categoria_id', (int) $asig['categoria_id'])
                ->pluck('id');

            if ($subIds->isEmpty()) continue;

            $affected = DB::connection('compras')
                ->table('inventarios')
                ->where('sucursal_id', $sucursalId)
                ->whereIn('producto_id', $subIds)
                ->update(['seccion' => $asig['seccion'] ?? null, 'aud_usuario' => $usuario, 'updated_at' => now()]);

            $total += $affected;
        }

        return response()->json(['success' => true, 'message' => "{$total} productos actualizados.", 'total' => $total]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PATCH /api/compras/inventario/{id}/seccion
    // Asigna el área física (SECOS, FRIOS, CUARTO FRIO, COCINA, OTROS) al item
    // ─────────────────────────────────────────────────────────────────────────
    public function actualizarSeccion(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'seccion' => 'nullable|string|max:50',
        ]);

        $inv = Inventario::findOrFail($id);
        $inv->update(['seccion' => $validated['seccion'] ?? null, 'aud_usuario' => Auth::user()->email]);

        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PATCH /api/compras/inventario/{id}/activo
    // Activa u oculta un producto del inventario (depuración por sucursal)
    // ─────────────────────────────────────────────────────────────────────────
    public function toggleActivo(int $id): JsonResponse
    {
        $inv = Inventario::findOrFail($id);
        $inv->update(['activo' => ! $inv->activo, 'aud_usuario' => Auth::user()->email]);

        return response()->json(['success' => true, 'activo' => (bool) $inv->activo]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/compras/inventario/aplicar-conteo
    // Aplica un conteo físico: genera movimientos conteo_fisico con la diferencia
    // Body: { sucursal_id, fecha_conteo, items: [{producto_id, cantidad_contada, unidad}] }
    // ─────────────────────────────────────────────────────────────────────────
    public function aplicarConteo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sucursal_id'               => 'required|integer',
            'fecha_conteo'              => 'required|date',
            'items'                     => 'required|array|min:1',
            'items.*.producto_id'       => 'required|integer',
            'items.*.cantidad_contada'  => 'required|numeric|min:0',
            'items.*.unidad'            => 'required|string|max:30',
            'items.*.secciones'         => 'nullable|array',
        ]);

        $sucursalId = (int) $validated['sucursal_id'];
        $fecha      = $validated['fecha_conteo'];
        $usuario    = Auth::user()->email;

        $productoIds = collect($validated['items'])->pluck('producto_id')->unique()->all();

        $inventarios = Inventario::where('sucursal_id', $sucursalId)
            ->whereIn('producto_id', $productoIds)
            ->get()
            ->keyBy('producto_id');

        $movimientos = DB::connection('compras')
            ->table('movimientos_inventario')
            ->where('sucursal_id', $sucursalId)
            ->whereIn('producto_id', $productoIds)
            ->where('tipo', '!=', 'carga_inicial')
            ->selectRaw('producto_id, SUM(cantidad_base) as total_base')
            ->groupBy('producto_id')
            ->pluck('total_base', 'producto_id');

        $productos = DB::connection('compras')
            ->table('productos')
            ->whereIn('id', $productoIds)
            ->select('id', 'factor_conversion')
            ->get()
            ->keyBy('id');

        DB::connection('compras')->beginTransaction();
        try {
            $aplicados = 0;
            foreach ($validated['items'] as $item) {
                $pid = (int) $item['producto_id'];
                $inv = $inventarios[$pid] ?? null;

                // Producto de receta sin entrada previa en inventarios → crear con stock 0
                if (!$inv) {
                    $prod = $productos[$pid] ?? null;
                    if (!$prod) continue;

                    $inv = Inventario::create([
                        'sucursal_id'           => $sucursalId,
                        'producto_id'           => $pid,
                        'cantidad_inicial'      => 0,
                        'unidad'                => $item['unidad'],
                        'cantidad_inicial_base' => 0,
                        'fecha_conteo'          => $fecha,
                        'stock_minimo'          => null,
                        'aud_usuario'           => $usuario,
                    ]);
                    $inventarios[$pid] = $inv;
                }

                $factor          = max((float) ($productos[$pid]?->factor_conversion ?? 1), 0.0001);
                $movBase         = (float) ($movimientos[$pid] ?? 0);
                $stockActualBase = $inv->cantidad_inicial_base + $movBase;
                $cantadaBase     = (float) $item['cantidad_contada'] * $factor;
                $diferenciaBase  = $cantadaBase - $stockActualBase;

                if (abs($diferenciaBase) < 0.00001) continue;

                // Construir detalle por sección (solo las que tienen cantidad > 0)
                $detalle = null;
                if (!empty($item['secciones'])) {
                    $seccionesConValor = array_filter(
                        $item['secciones'],
                        fn($v) => is_numeric($v) && $v > 0
                    );
                    if (!empty($seccionesConValor)) {
                        $detalle = [
                            'secciones'       => $seccionesConValor,
                            'total_contado'   => round((float) $item['cantidad_contada'], 4),
                            'stock_anterior'  => round($stockActualBase / $factor, 4),
                        ];
                    }
                }

                MovimientoInventario::create([
                    'sucursal_id'     => $sucursalId,
                    'producto_id'     => $pid,
                    'tipo'            => 'conteo_fisico',
                    'cantidad'        => round($diferenciaBase / $factor, 4),
                    'unidad'          => $item['unidad'],
                    'cantidad_base'   => round($diferenciaBase, 6),
                    'motivo'          => "Conteo físico — {$fecha}",
                    'fecha'           => $fecha,
                    'referencia_tipo' => 'conteo',
                    'detalle'         => $detalle ? json_encode($detalle) : null,
                    'aud_usuario'     => $usuario,
                ]);
                $aplicados++;
            }

            DB::connection('compras')->commit();
        } catch (\Throwable $e) {
            DB::connection('compras')->rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'success'   => true,
            'message'   => "{$aplicados} productos ajustados por conteo físico.",
            'aplicados' => $aplicados,
        ]);
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
            'detalle'        => $m->detalle ? json_decode($m->detalle, true) : null,
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

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /api/compras/inventario/borrador?sucursal_id=X
    // PUT  /api/compras/inventario/borrador
    // DELETE /api/compras/inventario/borrador?sucursal_id=X
    // Borrador de conteo físico persistido en DB por sucursal + usuario
    // ─────────────────────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/inventario/conteo-hoy?sucursal_id=X&fecha=Y
    // Devuelve el último conteo físico del día por producto (total + secciones)
    // ─────────────────────────────────────────────────────────────────────────
    public function conteoHoy(Request $request): JsonResponse
    {
        $request->validate(['sucursal_id' => 'required|integer', 'fecha' => 'required|date']);

        $sucursalId = (int) $request->query('sucursal_id');
        $fecha      = $request->query('fecha');

        // Trae el último movimiento de conteo_fisico por producto para esa fecha
        $movs = DB::connection('compras')
            ->table('movimientos_inventario as m')
            ->join('productos as p', 'p.id', '=', 'm.producto_id')
            ->where('m.sucursal_id', $sucursalId)
            ->where('m.tipo', 'conteo_fisico')
            ->where('m.fecha', $fecha)
            ->select('m.producto_id', 'm.detalle', 'm.created_at', 'p.factor_conversion', 'p.unidad')
            ->orderBy('m.created_at')
            ->get();

        // Por producto: último movimiento gana (puede haber varios en el día)
        $byProd = [];
        foreach ($movs as $mov) {
            $d = is_string($mov->detalle) ? json_decode($mov->detalle, true) : (array)($mov->detalle ?? []);
            $byProd[$mov->producto_id] = [
                'producto_id'   => $mov->producto_id,
                'total_contado' => $d['total_contado'] ?? null,
                'secciones'     => $d['secciones']     ?? null,
            ];
        }

        return response()->json(['success' => true, 'data' => array_values($byProd)]);
    }

    public function getBorrador(Request $request): JsonResponse
    {
        $request->validate(['sucursal_id' => 'required|integer']);
        $row = DB::connection('compras')
            ->table('conteo_borradores')
            ->where('sucursal_id', (int) $request->query('sucursal_id'))
            ->where('aud_usuario', Auth::user()->email)
            ->first();

        if (!$row) return response()->json(['success' => true, 'data' => null]);

        return response()->json([
            'success' => true,
            'data'    => [
                'fecha_conteo' => $row->fecha_conteo,
                'payload'      => json_decode($row->payload, true),
                'updated_at'   => $row->updated_at,
            ],
        ]);
    }

    public function saveBorrador(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sucursal_id'  => 'required|integer',
            'fecha_conteo' => 'required|date',
            'payload'      => 'required|array',
        ]);

        DB::connection('compras')
            ->table('conteo_borradores')
            ->upsert(
                [[
                    'sucursal_id'  => (int) $validated['sucursal_id'],
                    'aud_usuario'  => Auth::user()->email,
                    'fecha_conteo' => $validated['fecha_conteo'],
                    'payload'      => json_encode($validated['payload']),
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]],
                ['sucursal_id', 'aud_usuario'],
                ['fecha_conteo', 'payload', 'updated_at']
            );

        return response()->json(['success' => true]);
    }

    public function deleteBorrador(Request $request): JsonResponse
    {
        $request->validate(['sucursal_id' => 'required|integer']);
        DB::connection('compras')
            ->table('conteo_borradores')
            ->where('sucursal_id', (int) $request->query('sucursal_id'))
            ->where('aud_usuario', Auth::user()->email)
            ->delete();

        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/inventario/estadisticas?sucursal_id=X
    // Dashboard de estadísticas: top negativos, excedentes, comparación mes,
    // pérdida monetaria y ranking por sucursal (admin).
    // ─────────────────────────────────────────────────────────────────────────
    public function estadisticas(Request $request): JsonResponse
    {
        $sucursalId = $request->query('sucursal_id') ? (int) $request->query('sucursal_id') : null;

        // ── Stock actual por producto (base units) ──────────────────────────
        // stock_actual_base = cantidad_inicial_base + SUM(movimientos.cantidad_base)
        $stockQuery = DB::connection('compras')
            ->table('inventarios as inv')
            ->leftJoin('movimientos_inventario as mov', function ($j) {
                $j->on('mov.producto_id', '=', 'inv.producto_id')
                  ->on('mov.sucursal_id', '=', 'inv.sucursal_id');
            })
            ->join('productos as p', 'p.id', '=', 'inv.producto_id')
            ->where('inv.activo', true)
            ->when($sucursalId, fn ($q) => $q->where('inv.sucursal_id', $sucursalId))
            ->groupBy('inv.id', 'inv.sucursal_id', 'inv.producto_id', 'inv.cantidad_inicial_base',
                      'inv.unidad', 'inv.stock_minimo', 'p.nombre', 'p.codigo', 'p.costo')
            ->selectRaw("
                inv.id,
                inv.sucursal_id,
                inv.producto_id,
                p.nombre   AS producto_nombre,
                p.codigo   AS producto_codigo,
                p.costo    AS costo_unitario,
                inv.unidad,
                inv.stock_minimo,
                inv.cantidad_inicial_base + COALESCE(SUM(mov.cantidad_base), 0) AS stock_base
            ");

        $items = $stockQuery->get();

        // ── Resumen general ─────────────────────────────────────────────────
        $total     = $items->count();
        $faltantes = $items->filter(fn ($r) => $r->stock_minimo !== null && (float)$r->stock_base < (float)$r->stock_minimo)->count();
        $ok        = $total - $faltantes;

        // Pérdida monetaria: costo de reponer cada faltante hasta su mínimo
        $perdidaTotal = $items
            ->filter(fn ($r) => $r->stock_minimo !== null
                && (float)$r->stock_base < (float)$r->stock_minimo
                && (float)$r->costo_unitario > 0)
            ->sum(fn ($r) => ((float)$r->stock_minimo - (float)$r->stock_base) * (float)$r->costo_unitario);

        // Valor sobrantes: lo que excede al mínimo en items con stock > 2× mínimo
        $valorExcedenteTotal = $items
            ->filter(fn ($r) => $r->stock_minimo !== null && (float)$r->stock_minimo > 0
                && (float)$r->stock_base > (float)$r->stock_minimo * 2
                && (float)$r->costo_unitario > 0)
            ->sum(fn ($r) => ((float)$r->stock_base - (float)$r->stock_minimo) * (float)$r->costo_unitario);

        // ── Top 10 faltantes (stock < mínimo, mayor déficit primero) ────────
        $topFaltantes = $items
            ->filter(fn ($r) => $r->stock_minimo !== null && (float)$r->stock_minimo > 0
                && (float)$r->stock_base < (float)$r->stock_minimo)
            ->map(fn ($r) => [
                'producto'       => $r->producto_nombre,
                'codigo'         => $r->producto_codigo,
                'stock'          => round((float)$r->stock_base, 3),
                'stock_minimo'   => (float)$r->stock_minimo,
                'deficit'        => round((float)$r->stock_minimo - (float)$r->stock_base, 3),
                'unidad'         => $r->unidad,
                'costo_reponer'  => (float)$r->costo_unitario > 0
                    ? round(((float)$r->stock_minimo - (float)$r->stock_base) * (float)$r->costo_unitario, 2) : null,
            ])
            ->sortByDesc('deficit')
            ->take(10)
            ->values();

        // ── Top 10 sobrantes (stock > 2× mínimo, mayor ratio primero) ───────
        $topSobrantes = $items
            ->filter(fn ($r) => $r->stock_minimo !== null && (float)$r->stock_minimo > 0
                && (float)$r->stock_base > (float)$r->stock_minimo * 2)
            ->map(fn ($r) => [
                'producto'        => $r->producto_nombre,
                'codigo'          => $r->producto_codigo,
                'stock'           => round((float)$r->stock_base, 3),
                'stock_minimo'    => (float)$r->stock_minimo,
                'sobrante'        => round((float)$r->stock_base - (float)$r->stock_minimo, 3),
                'ratio'           => round((float)$r->stock_base / (float)$r->stock_minimo, 1),
                'unidad'          => $r->unidad,
                'valor_sobrante'  => (float)$r->costo_unitario > 0
                    ? round(((float)$r->stock_base - (float)$r->stock_minimo) * (float)$r->costo_unitario, 2) : null,
            ])
            ->sortByDesc('ratio')
            ->take(10)
            ->values();

        // ── Comparación mes actual vs mes anterior ──────────────────────────
        $mesActual   = now()->startOfMonth()->toDateString();
        $mesAnterior = now()->subMonth()->startOfMonth()->toDateString();
        $finMesAnt   = now()->subMonth()->endOfMonth()->toDateString();

        $conteosQuery = DB::connection('compras')
            ->table('movimientos_inventario as m')
            ->join('productos as p', 'p.id', '=', 'm.producto_id')
            ->where('m.tipo', 'conteo_fisico')
            ->when($sucursalId, fn ($q) => $q->where('m.sucursal_id', $sucursalId))
            ->whereDate('m.fecha', '>=', $mesAnterior)
            ->selectRaw("
                m.producto_id,
                p.nombre AS producto_nombre,
                p.codigo AS producto_codigo,
                p.costo  AS costo_unitario,
                m.unidad,
                SUM(CASE WHEN m.fecha >= ? THEN m.cantidad_base ELSE 0 END) AS delta_actual,
                SUM(CASE WHEN m.fecha < ?  THEN m.cantidad_base ELSE 0 END) AS delta_anterior
            ", [$mesActual, $mesActual])
            ->groupBy('m.producto_id', 'p.nombre', 'p.codigo', 'p.costo', 'm.unidad')
            ->get();

        $topMayoresCambios = $conteosQuery
            ->map(fn ($r) => [
                'producto'   => $r->producto_nombre,
                'codigo'     => $r->producto_codigo,
                'unidad'     => $r->unidad,
                'actual'     => round((float)$r->delta_actual, 3),
                'anterior'   => round((float)$r->delta_anterior, 3),
                'diferencia' => round((float)$r->delta_actual - (float)$r->delta_anterior, 3),
            ])
            ->filter(fn ($r) => abs($r['diferencia']) > 0.001)
            ->sortByDesc(fn ($r) => abs($r['diferencia']))
            ->take(10)
            ->values();

        // ── Ranking por sucursal (admin: sin filtro de sucursal) ────────────
        $rankingSucursales = [];
        if (!$sucursalId) {
            $porSucursal = DB::connection('compras')
                ->table('inventarios as inv')
                ->leftJoin('movimientos_inventario as mov', function ($j) {
                    $j->on('mov.producto_id', '=', 'inv.producto_id')
                      ->on('mov.sucursal_id', '=', 'inv.sucursal_id');
                })
                ->where('inv.activo', true)
                ->groupBy('inv.sucursal_id')
                ->selectRaw("
                    inv.sucursal_id,
                    COUNT(DISTINCT inv.producto_id) AS total_items,
                    COUNT(DISTINCT CASE WHEN inv.cantidad_inicial_base + COALESCE(SUM(mov.cantidad_base) OVER (PARTITION BY inv.id), 0) <= 0 THEN inv.producto_id END) AS agotados,
                    SUM(CASE WHEN inv.cantidad_inicial_base + COALESCE(SUM(mov.cantidad_base) OVER (PARTITION BY inv.id), 0) < 0 THEN 1 ELSE 0 END) AS negativos
                ")
                ->get();

            // Simplificado: contar agotados directamente
            $stockPorSucursal = DB::connection('compras')
                ->table('inventarios as inv')
                ->leftJoin('movimientos_inventario as mov', function ($j) {
                    $j->on('mov.producto_id', '=', 'inv.producto_id')
                      ->on('mov.sucursal_id', '=', 'inv.sucursal_id');
                })
                ->where('inv.activo', true)
                ->groupBy('inv.sucursal_id', 'inv.producto_id', 'inv.cantidad_inicial_base', 'inv.stock_minimo')
                ->selectRaw("
                    inv.sucursal_id,
                    inv.producto_id,
                    inv.stock_minimo,
                    inv.cantidad_inicial_base + COALESCE(SUM(mov.cantidad_base), 0) AS stock_base
                ")
                ->get()
                ->groupBy('sucursal_id');

            $sucursalesNombres = DB::connection('pgsql')
                ->table('sucursales')
                ->pluck('nombre', 'id');

            foreach ($stockPorSucursal as $sucId => $items2) {
                $tot = $items2->count();
                $falt = $items2->filter(fn ($r) => $r->stock_minimo !== null && (float)$r->stock_base < (float)$r->stock_minimo)->count();
                $rankingSucursales[] = [
                    'sucursal_id'  => $sucId,
                    'sucursal'     => $sucursalesNombres[$sucId] ?? "Sucursal $sucId",
                    'total'        => $tot,
                    'faltantes'    => $falt,
                    'pct_faltante' => $tot > 0 ? round($falt / $tot * 100, 1) : 0,
                ];
            }
            usort($rankingSucursales, fn ($a, $b) => $b['pct_faltante'] <=> $a['pct_faltante']);
        }

        return response()->json([
            'resumen' => [
                'total'             => $total,
                'faltantes'         => $faltantes,
                'ok'                => $ok,
                'pct_faltante'      => $total > 0 ? round($faltantes / $total * 100, 1) : 0,
                'perdida_monetaria' => round($perdidaTotal, 2),
                'valor_sobrante'    => round($valorExcedenteTotal, 2),
            ],
            'top_faltantes'      => $topFaltantes,
            'top_sobrantes'      => $topSobrantes,
            'comparacion_mes'    => $topMayoresCambios,
            'ranking_sucursales' => $rankingSucursales,
        ]);
    }
}
