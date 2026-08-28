<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Mail\ConteoProdSegNotificacion;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Services\Compras\ConsumoInventarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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
                'prod_seg'            => (bool) ($inv->prod_seg ?? false),
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
            'tipo_conteo'               => 'nullable|in:conteo_fisico,conteo_mensual',
        ]);

        $sucursalId  = (int) $validated['sucursal_id'];
        $fecha       = $validated['fecha_conteo'];
        $usuario     = Auth::user()->email;
        $tipoConteo  = $validated['tipo_conteo'] ?? 'conteo_fisico';

        $productoIds = collect($validated['items'])->pluck('producto_id')->unique()->all();

        // ── Para conteo_mensual: detectar productos ya contados por OTRO usuario ──
        // Cada contador solo aplica sus propios productos; los conflictos se informan
        // pero NO bloquean los productos únicos del contador.
        $conflictos = [];
        if ($tipoConteo === 'conteo_mensual') {
            $yaContados = DB::connection('compras')
                ->table('movimientos_inventario as m')
                ->join('productos as p', 'p.id', '=', 'm.producto_id')
                ->where('m.sucursal_id', $sucursalId)
                ->where('m.tipo', 'conteo_mensual')
                ->where('m.fecha', $fecha)
                ->where('m.aud_usuario', '!=', $usuario)      // otro contador real
                ->where('m.aud_usuario', '!=', 'sin_contar') // ignorar placeholders automáticos
                ->whereIn('m.producto_id', $productoIds)
                ->select('m.producto_id', 'p.nombre', 'm.aud_usuario as contado_por')
                ->get();

            if ($yaContados->isNotEmpty()) {
                $conflictos      = $yaContados->toArray();
                $idsConflicto    = $yaContados->pluck('producto_id')->all();
                $productoIds     = array_values(array_diff($productoIds, $idsConflicto));
                $validated['items'] = array_values(array_filter(
                    $validated['items'],
                    fn($i) => !in_array((int) $i['producto_id'], $idsConflicto)
                ));
            }

            // Si no quedan ítems no-conflictivos, devolver solo los conflictos
            if (empty($validated['items'])) {
                return response()->json([
                    'success'    => true,
                    'aplicados'  => 0,
                    'conflictos' => $conflictos,
                    'message'    => 'Todos los productos enviados ya fueron contados por otro contador.',
                ]);
            }
        } else {
            // conteo_fisico: bloqueo clásico por re-aplicación
            $tieneConteoPrevio = DB::connection('compras')
                ->table('movimientos_inventario')
                ->where('sucursal_id', $sucursalId)
                ->where('tipo', $tipoConteo)
                ->where('fecha', $fecha)
                ->exists();

            if ($tieneConteoPrevio) {
                $authUser  = Auth::user();
                $esAdmin   = $authUser && (
                    $authUser->hasRole('admin_compras') ||
                    $authUser->hasRole('gerencia_financiera') ||
                    $authUser->hasRole('dir_comercial')
                );
                $esAuditor = $authUser && $authUser->hasPermission('puede_auditar_conteo');

                $tieneReapertura = ($esAuditor || $esAdmin) && DB::connection('compras')
                    ->table('conteo_reaperturas')
                    ->where('sucursal_id', $sucursalId)
                    ->where('fecha_conteo', $fecha)
                    ->where('created_at', '>=', now()->subHours(2))
                    ->exists();

                if (!$esAdmin && !$tieneReapertura) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El conteo de este día ya fue aplicado. Solo un auditor puede reabrirlo.',
                    ], 422);
                }
            }
        }

        // Cargar TODOS los inventarios activos de la sucursal (no solo los enviados)
        // para poder generar registros 0 de productos no contados.
        $todosInventarios = Inventario::where('sucursal_id', $sucursalId)
            ->get()
            ->keyBy('producto_id');

        $inventarios = $todosInventarios->only($productoIds);

        // Movimientos acumulados para cálculo de stock (solo productos enviados)
        $todosProductoIds = $todosInventarios->keys()->all();

        $movimientos = DB::connection('compras')
            ->table('movimientos_inventario')
            ->where('sucursal_id', $sucursalId)
            ->whereIn('producto_id', $todosProductoIds)
            ->where('tipo', '!=', 'carga_inicial')
            ->selectRaw('producto_id, SUM(cantidad_base) as total_base')
            ->groupBy('producto_id')
            ->pluck('total_base', 'producto_id');

        $productos = DB::connection('compras')
            ->table('productos')
            ->whereIn('id', $todosProductoIds)
            ->select('id', 'factor_conversion', 'unidad')
            ->get()
            ->keyBy('id');

        $motivoLabel = $tipoConteo === 'conteo_mensual' ? 'Conteo mensual' : 'Conteo físico';

        DB::connection('compras')->beginTransaction();
        try {
            $aplicados  = 0;
            $enviados   = collect($validated['items'])->keyBy('producto_id');

            foreach ($validated['items'] as $item) {
                $pid = (int) $item['producto_id'];
                $inv = $inventarios[$pid] ?? null;

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
                    $todosInventarios[$pid] = $inv;
                }

                $factor          = max((float) ($productos[$pid]?->factor_conversion ?? 1), 0.0001);
                $movBase         = (float) ($movimientos[$pid] ?? 0);
                $stockActualBase = $inv->cantidad_inicial_base + $movBase;
                $cantadaBase     = (float) $item['cantidad_contada'] * $factor;
                $diferenciaBase  = $cantadaBase - $stockActualBase;

                $seccionesConValor = [];
                if (!empty($item['secciones'])) {
                    $seccionesConValor = array_filter(
                        $item['secciones'],
                        fn($v) => is_numeric($v) && $v > 0
                    );
                }

                // detalle siempre completo: nunca null
                $detalle = [
                    'secciones'      => (object) $seccionesConValor,
                    'total_contado'  => round((float) $item['cantidad_contada'], 4),
                    'stock_anterior' => round($stockActualBase / $factor, 4),
                    'contado_por'    => $usuario,
                ];

                // Eliminar placeholder sin_contar si existe para que el real tome su lugar
                DB::connection('compras')
                    ->table('movimientos_inventario')
                    ->where('sucursal_id', $sucursalId)
                    ->where('producto_id', $pid)
                    ->where('tipo', $tipoConteo)
                    ->where('fecha', $fecha)
                    ->where('aud_usuario', 'sin_contar')
                    ->delete();

                // Guardar el movimiento real (incluso si cantidad_contada = 0)
                MovimientoInventario::create([
                    'sucursal_id'     => $sucursalId,
                    'producto_id'     => $pid,
                    'tipo'            => $tipoConteo,
                    'cantidad'        => round($diferenciaBase / $factor, 4),
                    'unidad'          => $item['unidad'],
                    'cantidad_base'   => round($diferenciaBase, 6),
                    'motivo'          => "{$motivoLabel} — {$fecha}",
                    'fecha'           => $fecha,
                    'referencia_tipo' => 'conteo',
                    'detalle'         => $detalle,
                    'aud_usuario'     => $usuario,
                ]);

                $aplicados++;
            }

            // ── Guardar 0 para productos no contados (por nadie aún) ─────────
            // Esto completa el snapshot del conteo aunque el producto no haya
            // sido registrado por ningún contador. Sin efecto en stock.
            $yaContadosIds = DB::connection('compras')
                ->table('movimientos_inventario')
                ->where('sucursal_id', $sucursalId)
                ->where('tipo', $tipoConteo)
                ->where('fecha', $fecha)
                ->pluck('producto_id')
                ->unique()
                ->all();

            foreach ($todosInventarios as $ncPid => $ncInv) {
                if (in_array($ncPid, $yaContadosIds)) continue;

                MovimientoInventario::create([
                    'sucursal_id'     => $sucursalId,
                    'producto_id'     => $ncPid,
                    'tipo'            => $tipoConteo,
                    'cantidad'        => 0,
                    'unidad'          => $ncInv->unidad,
                    'cantidad_base'   => 0,
                    'motivo'          => "{$motivoLabel} — {$fecha} — sin registro",
                    'fecha'           => $fecha,
                    'referencia_tipo' => 'conteo',
                    'detalle'         => [
                        'secciones'      => (object) [],
                        'total_contado'  => 0,
                        'stock_anterior' => null,
                        'contado_por'    => 'sin_contar',
                    ],
                    'aud_usuario'     => 'sin_contar',
                ]);
            }

            DB::connection('compras')->commit();
        } catch (\Throwable $e) {
            DB::connection('compras')->rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        // Notificación Brilo solo para conteo_fisico (conteo_mensual no compara con Brilo aquí)
        if ($tipoConteo !== 'conteo_mensual') {
            try {
                $this->enviarNotificacionConteoProdSeg($sucursalId, $fecha, $usuario, $validated['items'], $inventarios, $productos, $movimientos);
            } catch (\Throwable) {}
        }

        $msg = $tipoConteo === 'conteo_mensual'
            ? "{$aplicados} productos aplicados al conteo mensual."
            : "{$aplicados} productos ajustados por conteo físico.";

        return response()->json([
            'success'    => true,
            'message'    => $msg,
            'aplicados'  => $aplicados,
            'conflictos' => $conflictos ?? [],
        ]);
    }

    private function enviarNotificacionConteoProdSeg(
        int    $sucursalId,
        string $fecha,
        string $aplicadoPor,
        array  $itemsConteo,
        $inventarios,
        $productos,
        $movimientos,
    ): void {
        $prodSegItems = DB::connection('compras')
            ->table('inventarios as i')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->join('sucursales as s', 's.id', '=', 'i.sucursal_id')
            ->where('i.sucursal_id', $sucursalId)
            ->where('i.prod_seg', true)
            ->select('i.producto_id', 'p.nombre', 'i.unidad', 'i.brilo_stock', 's.nombre as sucursal_nombre')
            ->get();

        if ($prodSegItems->isEmpty()) return;

        $sucursalNombre = $prodSegItems->first()->sucursal_nombre;

        // Mapa de cantidades contadas en este conteo, indexado por producto_id
        $conteoMap = collect($itemsConteo)->keyBy('producto_id');

        $emailItems = $prodSegItems->map(function ($row) use ($conteoMap, $inventarios, $productos, $movimientos) {
            $pid      = $row->producto_id;
            $inv      = $inventarios[$pid] ?? null;
            $factor   = max((float) ($productos[$pid]?->factor_conversion ?? 1), 0.0001);
            $movBase  = (float) ($movimientos[$pid] ?? 0);
            $stockBase = $inv ? ($inv->cantidad_inicial_base + $movBase) : 0;
            $stock    = round($stockBase / $factor, 3);

            $conteoItem = $conteoMap[$pid] ?? null;
            $conteo     = $conteoItem ? round((float) $conteoItem['cantidad_contada'], 3) : null;
            $brilo      = $row->brilo_stock !== null ? round((float) $row->brilo_stock, 3) : null;
            $diferencia = ($brilo !== null && $conteo !== null) ? round($conteo - $brilo, 3) : null;

            $tipo = 'SIN DATOS';
            if ($diferencia !== null) {
                if ($diferencia > 0.01)       $tipo = 'SOBRANTE';
                elseif ($diferencia < -0.01)  $tipo = 'FALTANTE';
                else                          $tipo = 'OK';
            }

            return [
                'nombre'      => $row->nombre,
                'unidad'      => $row->unidad,
                'brilo_stock' => $brilo,
                'conteo'      => $conteo,
                'diferencia'  => $diferencia,
                'tipo'        => $tipo,
            ];
        })->values()->all();

        $destinatarios = [
            ['email' => 'enriqueduran@cervezacadejo.com',  'name' => 'Manuel Enrique Duran Rivas'],
            ['email' => 'marcelaorellana@cervezacadejo.com', 'name' => 'Ana Marcela Orellana'],
            ['email' => 'javiermejia@cervezacadejo.com',   'name' => 'Javier Francisco Mejia'],
        ];

        $mailable = new ConteoProdSegNotificacion($sucursalNombre, $fecha, $aplicadoPor, $emailItems);

        foreach ($destinatarios as $dest) {
            Mail::to($dest['email'], $dest['name'])->send($mailable);
        }
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
            'created_at'     => $m->created_at?->toIso8601String(),
            'aud_usuario'    => $m->aud_usuario,
            'detalle'        => $m->detalle,
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
        $tipo       = in_array($request->query('tipo_conteo'), ['conteo_fisico', 'conteo_mensual'])
                        ? $request->query('tipo_conteo')
                        : 'conteo_fisico';

        // Trae el último movimiento del tipo indicado por producto para esa fecha
        $movs = DB::connection('compras')
            ->table('movimientos_inventario as m')
            ->join('productos as p', 'p.id', '=', 'm.producto_id')
            ->where('m.sucursal_id', $sucursalId)
            ->where('m.tipo', $tipo)
            ->where('m.fecha', $fecha)
            ->select('m.producto_id', 'm.detalle', 'm.created_at', 'p.factor_conversion', 'p.unidad')
            ->orderBy('m.created_at')
            ->get();

        // Por producto: último movimiento gana (puede haber varios en el día)
        $byProd    = [];
        $appliedAt = null; // timestamp del conteo más reciente del día
        foreach ($movs as $mov) {
            $d = is_string($mov->detalle) ? json_decode($mov->detalle, true) : (array)($mov->detalle ?? []);
            $byProd[$mov->producto_id] = [
                'producto_id'   => $mov->producto_id,
                'total_contado' => $d['total_contado'] ?? null,
                'secciones'     => $d['secciones']     ?? null,
            ];
            if (!$appliedAt || $mov->created_at > $appliedAt) {
                $appliedAt = $mov->created_at;
            }
        }

        return response()->json([
            'success'    => true,
            'data'       => array_values($byProd),
            'applied_at' => $appliedAt,
        ]);
    }

    // POST /api/compras/inventario/reabrir-conteo
    // Auditor reabre un conteo cerrado (dentro de 2 horas). Registra motivo.
    public function reabrirConteo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sucursal_id'  => 'required|integer',
            'fecha_conteo' => 'required|date',
            'motivo'       => 'required|string|max:1000',
        ]);

        $authUser = Auth::user();
        $esAdmin  = $authUser->hasRole('admin_compras') || $authUser->hasRole('gerencia_financiera') || $authUser->hasRole('dir_comercial');
        $esAuditor = $authUser->hasPermission('puede_auditar_conteo');

        if (!$esAdmin && !$esAuditor) {
            return response()->json(['success' => false, 'message' => 'No tienes permiso para reabrir conteos.'], 403);
        }

        $sucursalId = (int) $validated['sucursal_id'];
        $fecha      = $validated['fecha_conteo'];

        // Verificar que existe un conteo para ese día
        $ultimoConteo = DB::connection('compras')
            ->table('movimientos_inventario')
            ->where('sucursal_id', $sucursalId)
            ->where('tipo', 'conteo_fisico')
            ->where('fecha', $fecha)
            ->orderByDesc('created_at')
            ->value('created_at');

        if (!$ultimoConteo) {
            return response()->json(['success' => false, 'message' => 'No existe conteo para esa fecha.'], 422);
        }

        // Ventana de 2 horas para no-admins
        if (!$esAdmin) {
            $aplicadoAt = \Carbon\Carbon::parse($ultimoConteo);
            if ($aplicadoAt->diffInMinutes(now()) > 120) {
                return response()->json(['success' => false, 'message' => 'La ventana de 2 horas para reabrir el conteo ha expirado.'], 422);
            }
        }

        DB::connection('compras')->table('conteo_reaperturas')->insert([
            'sucursal_id'  => $sucursalId,
            'fecha_conteo' => $fecha,
            'motivo'       => $validated['motivo'],
            'aud_usuario'  => $authUser->email,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Conteo reabierto. Puedes volver a aplicar el conteo.']);
    }

    // GET /api/compras/inventario/solicitudes-correccion
    public function getSolicitudesCorreccion(Request $request): JsonResponse
    {
        $sucursalId = $request->query('sucursal_id') ? (int) $request->query('sucursal_id') : null;
        $estado     = $request->query('estado', 'pendiente');

        $q = DB::connection('compras')
            ->table('solicitudes_correccion_conteo as s')
            ->join('productos as p', 'p.id', '=', 's.producto_id')
            ->select('s.*', 'p.nombre as producto_nombre', 'p.codigo as producto_codigo')
            ->where('s.estado', $estado)
            ->when($sucursalId, fn ($q) => $q->where('s.sucursal_id', $sucursalId))
            ->orderByDesc('s.created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $q]);
    }

    // POST /api/compras/inventario/solicitar-correccion
    public function solicitarCorreccion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sucursal_id'    => 'required|integer',
            'fecha_conteo'   => 'required|date',
            'producto_id'    => 'required|integer',
            'cantidad_nueva' => 'required|numeric|min:0',
            'unidad'         => 'required|string|max:30',
            'motivo'         => 'required|string|max:1000',
        ]);

        $validated['solicitado_por'] = Auth::user()->email;
        $validated['estado']         = 'pendiente';
        $validated['created_at']     = now();
        $validated['updated_at']     = now();

        $id = DB::connection('compras')
            ->table('solicitudes_correccion_conteo')
            ->insertGetId($validated);

        return response()->json(['success' => true, 'id' => $id, 'message' => 'Solicitud de corrección enviada al auditor.']);
    }

    // POST /api/compras/inventario/solicitudes-correccion/{id}/aprobar
    public function aprobarCorreccion(Request $request, int $id): JsonResponse
    {
        $authUser = Auth::user();
        $esAdmin  = $authUser->hasRole('admin_compras') || $authUser->hasRole('gerencia_financiera') || $authUser->hasRole('dir_comercial');
        $esAuditor = $authUser->hasPermission('puede_auditar_conteo');
        if (!$esAdmin && !$esAuditor) {
            return response()->json(['success' => false, 'message' => 'No autorizado.'], 403);
        }

        $sol = DB::connection('compras')
            ->table('solicitudes_correccion_conteo')
            ->where('id', $id)
            ->where('estado', 'pendiente')
            ->first();

        if (!$sol) {
            return response()->json(['success' => false, 'message' => 'Solicitud no encontrada o ya procesada.'], 404);
        }

        // Calcular stock actual y diferencia para generar el ajuste
        $inv = Inventario::where('sucursal_id', $sol->sucursal_id)
            ->where('producto_id', $sol->producto_id)
            ->first();

        if (!$inv) {
            return response()->json(['success' => false, 'message' => 'Producto no tiene inventario en esa sucursal.'], 422);
        }

        $producto = DB::connection('compras')->table('productos')->where('id', $sol->producto_id)->first();
        $factor   = max((float) ($producto->factor_conversion ?? 1), 0.0001);

        $movBase = DB::connection('compras')
            ->table('movimientos_inventario')
            ->where('sucursal_id', $sol->sucursal_id)
            ->where('producto_id', $sol->producto_id)
            ->where('tipo', '!=', 'carga_inicial')
            ->sum('cantidad_base');

        $stockActualBase  = $inv->cantidad_inicial_base + (float) $movBase;
        $cantadaNuevaBase = (float) $sol->cantidad_nueva * $factor;
        $diferenciaBase   = $cantadaNuevaBase - $stockActualBase;

        DB::connection('compras')->beginTransaction();
        try {
            MovimientoInventario::create([
                'sucursal_id'     => $sol->sucursal_id,
                'producto_id'     => $sol->producto_id,
                'tipo'            => 'correccion_conteo',
                'cantidad'        => round($diferenciaBase / $factor, 4),
                'unidad'          => $sol->unidad,
                'cantidad_base'   => round($diferenciaBase, 6),
                'motivo'          => "Corrección de conteo aprobada — {$sol->fecha_conteo}: {$sol->motivo}",
                'fecha'           => $sol->fecha_conteo,
                'referencia_tipo' => 'correccion',
                'detalle'         => [
                    'total_contado'    => round((float) $sol->cantidad_nueva, 4),
                    'stock_anterior'   => round($stockActualBase / $factor, 4),
                    'solicitud_id'     => $id,
                    'aprobado_por'     => $authUser->email,
                ],
                'aud_usuario' => $authUser->email,
            ]);

            DB::connection('compras')->table('solicitudes_correccion_conteo')
                ->where('id', $id)
                ->update([
                    'estado'       => 'aprobado',
                    'revisado_por' => $authUser->email,
                    'updated_at'   => now(),
                ]);

            DB::connection('compras')->commit();
        } catch (\Throwable $e) {
            DB::connection('compras')->rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => true, 'message' => 'Corrección aprobada y ajuste aplicado.']);
    }

    // POST /api/compras/inventario/solicitudes-correccion/{id}/rechazar
    public function rechazarCorreccion(Request $request, int $id): JsonResponse
    {
        $authUser = Auth::user();
        $esAdmin  = $authUser->hasRole('admin_compras') || $authUser->hasRole('gerencia_financiera') || $authUser->hasRole('dir_comercial');
        $esAuditor = $authUser->hasPermission('puede_auditar_conteo');
        if (!$esAdmin && !$esAuditor) {
            return response()->json(['success' => false, 'message' => 'No autorizado.'], 403);
        }

        $validated = $request->validate(['motivo_rechazo' => 'nullable|string|max:500']);

        $updated = DB::connection('compras')
            ->table('solicitudes_correccion_conteo')
            ->where('id', $id)
            ->where('estado', 'pendiente')
            ->update([
                'estado'          => 'rechazado',
                'revisado_por'    => $authUser->email,
                'motivo_rechazo'  => $validated['motivo_rechazo'] ?? null,
                'updated_at'      => now(),
            ]);

        if (!$updated) {
            return response()->json(['success' => false, 'message' => 'Solicitud no encontrada o ya procesada.'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Solicitud rechazada.']);
    }

    // GET /api/compras/inventario/borradores-activos?sucursal_id=X
    // Devuelve TODOS los borradores activos de la sucursal (cualquier usuario)
    public function borradoresActivos(Request $request): JsonResponse
    {
        $request->validate(['sucursal_id' => 'required|integer']);
        $rows = DB::connection('compras')
            ->table('conteo_borradores')
            ->where('sucursal_id', (int) $request->query('sucursal_id'))
            ->where('estado', 'borrador')
            ->orderByDesc('updated_at')
            ->get();

        $data = $rows->map(fn($r) => [
            'aud_usuario'  => $r->aud_usuario,
            'fecha_conteo' => $r->fecha_conteo,
            'updated_at'   => $r->updated_at,
            'payload'      => json_decode($r->payload, true),
        ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function getBorrador(Request $request): JsonResponse
    {
        $request->validate(['sucursal_id' => 'required|integer']);
        $row = DB::connection('compras')
            ->table('conteo_borradores')
            ->where('sucursal_id', (int) $request->query('sucursal_id'))
            ->where('aud_usuario', Auth::user()->email)
            ->where('estado', 'borrador')
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
        // Soft-delete: marcar como descartado en lugar de borrar físicamente
        DB::connection('compras')
            ->table('conteo_borradores')
            ->where('sucursal_id', (int) $request->query('sucursal_id'))
            ->where('aud_usuario', Auth::user()->email)
            ->where('estado', 'borrador')
            ->update(['estado' => 'descartado', 'updated_at' => now()]);

        return response()->json(['success' => true]);
    }

    // PATCH /api/compras/inventario/borrador/aplicado
    // Marca el borrador como aplicado (llamado justo después de aplicarConteo)
    public function marcarBorradorAplicado(Request $request): JsonResponse
    {
        $request->validate(['sucursal_id' => 'required|integer']);
        DB::connection('compras')
            ->table('conteo_borradores')
            ->where('sucursal_id', (int) $request->query('sucursal_id'))
            ->where('aud_usuario', Auth::user()->email)
            ->where('estado', 'borrador')
            ->update([
                'estado'      => 'aplicado',
                'aplicado_en' => now(),
                'updated_at'  => now(),
            ]);

        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/inventario/fechas-conteo?sucursal_id=X
    // Retorna array de fechas (YYYY-MM-DD) que tienen conteo_fisico aplicado.
    // ─────────────────────────────────────────────────────────────────────────
    public function fechasConteo(Request $request): JsonResponse
    {
        $sucursalId = $request->query('sucursal_id') ? (int) $request->query('sucursal_id') : null;
        if (!$sucursalId) {
            return response()->json(['data' => []]);
        }

        $tipoFechas = in_array($request->query('tipo_conteo'), ['conteo_fisico', 'conteo_mensual'])
                        ? $request->query('tipo_conteo')
                        : 'conteo_fisico';

        $fechas = DB::connection('compras')
            ->table('movimientos_inventario')
            ->where('tipo', $tipoFechas)
            ->where('sucursal_id', $sucursalId)
            ->selectRaw('DATE(fecha)::text AS fecha')
            ->groupBy(DB::raw('DATE(fecha)'))
            ->orderBy(DB::raw('DATE(fecha)'), 'desc')
            ->pluck('fecha');

        return response()->json(['data' => $fechas]);
    }

    // GET /api/compras/inventario/estadisticas?sucursal_id=X
    // Dashboard de estadísticas: top faltantes, sobrantes, comparación mes,
    // pérdida monetaria y ranking por sucursal (admin).
    // Usa el mismo cálculo de stock que index(): factor_conversion, excluye carga_inicial.
    // ─────────────────────────────────────────────────────────────────────────
    public function estadisticas(Request $request): JsonResponse
    {
        $sucursalId = $request->query('sucursal_id') ? (int) $request->query('sucursal_id') : null;
        $fecha      = $request->query('fecha'); // YYYY-MM-DD opcional

        // ── Guard: si se filtra por sucursal, verificar que haya un conteo aplicado ──
        // Sin conteo real las estadísticas no tienen sentido (brilo vs 0 no refleja nada)
        if ($sucursalId) {
            $query = DB::connection('compras')
                ->table('movimientos_inventario')
                ->where('tipo', 'conteo_fisico')
                ->where('sucursal_id', $sucursalId);

            if ($fecha) {
                $query->whereDate('fecha', $fecha);
            }

            if (!$query->exists()) {
                return response()->json(['sin_datos' => true, 'mensaje' => 'No hay conteo físico aplicado para esta sucursal' . ($fecha ? " en la fecha $fecha" : '') . '.']);
            }
        }

        // ── Calcular stock por sucursal usando el mismo método que index() ──
        // stock_actual = (cantidad_inicial_base + SUM(mov excl. carga_inicial)) / factor_conversion
        $items = $this->calcularStockItems($sucursalId);

        // ── Resumen: compara stock_actual vs brilo_stock (igual que el Excel de resultado) ──
        // Solo se consideran items donde brilo_stock tiene valor (BRILO los conoce)
        $itemsConBrilo = $items->filter(fn ($r) => $r->brilo_stock !== null);
        $total         = $items->count();
        $conBrilo      = $itemsConBrilo->count();
        $faltantes     = $itemsConBrilo->filter(fn ($r) => $r->stock_actual < $r->brilo_stock)->count();
        $sobrantes     = $itemsConBrilo->filter(fn ($r) => $r->stock_actual > $r->brilo_stock)->count();
        $ok            = $conBrilo - $faltantes - $sobrantes;

        // Pérdida monetaria: (brilo - contado) × costo para faltantes
        $perdidaTotal = $itemsConBrilo
            ->filter(fn ($r) => $r->stock_actual < $r->brilo_stock && $r->costo > 0)
            ->sum(fn ($r) => ($r->brilo_stock - $r->stock_actual) * $r->costo);

        // Valor sobrantes: (contado - brilo) × costo para sobrantes
        $valorSobranteTotal = $itemsConBrilo
            ->filter(fn ($r) => $r->stock_actual > $r->brilo_stock && $r->costo > 0)
            ->sum(fn ($r) => ($r->stock_actual - $r->brilo_stock) * $r->costo);

        // ── Top 10 faltantes (contado < brilo, mayor déficit primero) ────────
        $topFaltantes = $itemsConBrilo
            ->filter(fn ($r) => $r->stock_actual < $r->brilo_stock)
            ->map(fn ($r) => [
                'producto'      => $r->producto_nombre,
                'codigo'        => $r->producto_codigo,
                'stock'         => round($r->stock_actual, 3),
                'brilo_stock'   => round($r->brilo_stock, 3),
                'deficit'       => round($r->brilo_stock - $r->stock_actual, 3),
                'unidad'        => $r->unidad,
                'costo_reponer' => $r->costo > 0
                    ? round(($r->brilo_stock - $r->stock_actual) * $r->costo, 2) : null,
            ])
            ->sortByDesc('deficit')
            ->take(10)
            ->values();

        // ── Top 10 sobrantes (contado > brilo, mayor diferencia primero) ────
        $topSobrantes = $itemsConBrilo
            ->filter(fn ($r) => $r->stock_actual > $r->brilo_stock)
            ->map(fn ($r) => [
                'producto'      => $r->producto_nombre,
                'codigo'        => $r->producto_codigo,
                'stock'         => round($r->stock_actual, 3),
                'brilo_stock'   => round($r->brilo_stock, 3),
                'sobrante'      => round($r->stock_actual - $r->brilo_stock, 3),
                'unidad'        => $r->unidad,
                'valor_sobrante'=> $r->costo > 0
                    ? round(($r->stock_actual - $r->brilo_stock) * $r->costo, 2) : null,
            ])
            ->sortByDesc('sobrante')
            ->take(10)
            ->values();

        // ── Comparación mes actual vs mes anterior ──────────────────────────
        $mesActual   = now()->startOfMonth()->toDateString();
        $mesAnterior = now()->subMonth()->startOfMonth()->toDateString();

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
                m.unidad,
                SUM(CASE WHEN m.fecha >= ? THEN m.cantidad_base ELSE 0 END) AS delta_actual,
                SUM(CASE WHEN m.fecha < ?  THEN m.cantidad_base ELSE 0 END) AS delta_anterior
            ", [$mesActual, $mesActual])
            ->groupBy('m.producto_id', 'p.nombre', 'p.codigo', 'm.unidad')
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
            $sucursalesIds = DB::connection('compras')
                ->table('inventarios')
                ->where('activo', true)
                ->distinct()
                ->pluck('sucursal_id');

            $sucursalesNombres = DB::connection('pgsql')
                ->table('sucursales')
                ->pluck('nombre', 'id');

            foreach ($sucursalesIds as $sucId) {
                $items2     = $this->calcularStockItems($sucId);
                $c2         = $items2->filter(fn ($r) => $r->brilo_stock !== null);
                $tot        = $c2->count();
                $falt       = $c2->filter(fn ($r) => $r->stock_actual < $r->brilo_stock)->count();
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

        // Última fecha de conteo físico para esta sucursal
        $ultimoConteo = DB::connection('compras')
            ->table('movimientos_inventario')
            ->where('tipo', 'conteo_fisico')
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->max(DB::raw('DATE(fecha)'));

        return response()->json([
            'resumen' => [
                'total'             => $total,
                'con_brilo'         => $conBrilo,
                'faltantes'         => $faltantes,
                'sobrantes'         => $sobrantes,
                'ok'                => $ok,
                'pct_faltante'      => $conBrilo > 0 ? round($faltantes / $conBrilo * 100, 1) : 0,
                'perdida_monetaria' => round($perdidaTotal, 2),
                'valor_sobrante'    => round($valorSobranteTotal, 2),
            ],
            'top_faltantes'      => $topFaltantes,
            'top_sobrantes'      => $topSobrantes,
            'comparacion_mes'    => $topMayoresCambios,
            'ranking_sucursales' => $rankingSucursales,
            'ultimo_conteo'      => $ultimoConteo,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Calcula stock_actual usando el mismo método que index():
    // (cantidad_inicial_base + SUM(movimientos excl. carga_inicial)) / factor_conversion
    // ─────────────────────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/inventario/prod-seg
    // Retorna los productos marcados prod_seg=true con brilo_stock, conteo físico
    // más reciente y diferencia, por sucursal. Solo para admins.
    // Query params: sucursal_id (opcional) — si se omite devuelve todas.
    // ─────────────────────────────────────────────────────────────────────────
    public function prodSegStats(Request $request): JsonResponse
    {
        $sucursalId = $request->query('sucursal_id') ? (int) $request->query('sucursal_id') : null;

        $rows = DB::connection('compras')
            ->table('inventarios as i')
            ->join('productos as p', 'p.id', '=', 'i.producto_id')
            ->join('sucursales as s', 's.id', '=', 'i.sucursal_id')
            ->where('i.prod_seg', true)
            ->where('i.activo', true)
            ->when($sucursalId, fn ($q) => $q->where('i.sucursal_id', $sucursalId))
            ->select(
                'i.sucursal_id',
                's.nombre as sucursal_nombre',
                'i.producto_id',
                'p.nombre as producto_nombre',
                'p.codigo as producto_codigo',
                'i.unidad',
                'i.brilo_stock',
                'i.brilo_sync_at',
                'i.cantidad_inicial_base',
                'p.factor_conversion',
            )
            ->orderBy('s.nombre')
            ->orderBy('p.nombre')
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['sucursales' => []]);
        }

        $productoIds  = $rows->pluck('producto_id')->unique()->all();
        $sucursalIds  = $rows->pluck('sucursal_id')->unique()->all();

        // Último conteo físico por sucursal+producto (total_contado real del gerente)
        $sucursalIdList = implode(',', array_map('intval', $sucursalIds));
        $productoIdList = implode(',', array_map('intval', $productoIds));

        $ultimoConteoRaw = DB::connection('compras')->select("
            SELECT DISTINCT ON (sucursal_id, producto_id)
                sucursal_id, producto_id, fecha,
                (detalle::json->>'total_contado')::numeric AS total_contado
            FROM movimientos_inventario
            WHERE tipo = 'conteo_fisico'
              AND sucursal_id IN ({$sucursalIdList})
              AND producto_id IN ({$productoIdList})
            ORDER BY sucursal_id, producto_id, created_at DESC
        ");

        $ultimosConteos = collect($ultimoConteoRaw)
            ->groupBy('sucursal_id')
            ->map(fn ($g) => $g->keyBy('producto_id'));

        $grouped = $rows->groupBy('sucursal_id')->map(function ($items, $sucId) use ($ultimosConteos) {
            $sucursalConteos = $ultimosConteos[$sucId] ?? collect();

            $productos = $items->map(function ($r) use ($sucursalConteos) {
                $pid  = $r->producto_id;
                $brilo = $r->brilo_stock !== null ? round((float) $r->brilo_stock, 3) : null;

                $ultimoConteo = $sucursalConteos[$pid] ?? null;
                $conteoFisico = $ultimoConteo && $ultimoConteo->total_contado !== null
                    ? round((float) $ultimoConteo->total_contado, 3)
                    : null;

                $diferencia = ($brilo !== null && $conteoFisico !== null)
                    ? round($conteoFisico - $brilo, 3)
                    : null;

                $tipo = null;
                if ($diferencia !== null) {
                    if ($diferencia > 0.01)      $tipo = 'SOBRANTE';
                    elseif ($diferencia < -0.01) $tipo = 'FALTANTE';
                    else                         $tipo = 'OK';
                }

                return [
                    'producto_id'   => $pid,
                    'nombre'        => $r->producto_nombre,
                    'codigo'        => $r->producto_codigo,
                    'unidad'        => $r->unidad,
                    'conteo_fisico' => $conteoFisico,
                    'brilo_stock'   => $brilo,
                    'brilo_sync_at' => $r->brilo_sync_at,
                    'diferencia'    => $diferencia,
                    'tipo'          => $tipo,
                    'ultimo_conteo' => $ultimoConteo?->fecha,
                ];
            })->values()->all();

            return [
                'sucursal_id'     => $sucId,
                'sucursal_nombre' => $items->first()->sucursal_nombre,
                'productos'       => $productos,
            ];
        })->values()->all();

        return response()->json(['sucursales' => $grouped]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/inventario/prod-seg-historico
    // Histórico diario de brilo_stock vs conteo_fisico de productos prod_seg.
    // Query params: sucursal_id (requerido), dias (default 30)
    // ─────────────────────────────────────────────────────────────────────────
    public function prodSegHistorico(Request $request): JsonResponse
    {
        $sucursalId = $request->query('sucursal_id') ? (int) $request->query('sucursal_id') : null;
        $dias       = min((int) ($request->query('dias', 30)), 90);
        $desde      = now()->subDays($dias)->toDateString();

        $rows = DB::connection('compras')
            ->table('prod_seg_historico as h')
            ->join('productos as p', 'p.id', '=', 'h.producto_id')
            ->when($sucursalId, fn ($q) => $q->where('h.sucursal_id', $sucursalId))
            ->where('h.fecha', '>=', $desde)
            ->select(
                'h.sucursal_id',
                'h.producto_id',
                'p.codigo as producto_codigo',
                'p.nombre as producto_nombre',
                'p.unidad',
                'h.fecha',
                DB::raw("(h.sync_at AT TIME ZONE 'UTC' AT TIME ZONE 'America/El_Salvador') AS sync_at"),
                'h.brilo_stock',
                'h.conteo_fisico',
                'h.diferencia',
            )
            ->orderBy('h.fecha', 'desc')
            ->orderBy('h.sucursal_id')
            ->orderBy('p.nombre')
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['sucursales' => [], 'fechas' => []]);
        }

        // Para filas sin conteo en el snapshot, buscar en movimientos_inventario.
        // Todas las sucursales usan fecha directa (conteo.fecha = historico.fecha).
        $sinConteo = $rows->filter(fn ($r) => is_null($r->conteo_fisico));
        if ($sinConteo->isNotEmpty()) {
            $pdo     = DB::connection('compras')->getPdo();
            $sucIds  = implode(',', array_map('intval', $sinConteo->pluck('sucursal_id')->unique()->values()->all()));
            $prodIds = implode(',', array_map('intval', $sinConteo->pluck('producto_id')->unique()->values()->all()));
            $fechasQ = implode(',', $sinConteo->pluck('fecha')->unique()->map(fn ($f) => $pdo->quote((string) $f))->all());

            // Para nocturnas: la clave de join es fecha_historico = movimiento.fecha + 1 día
            // Para normales:  la clave de join es fecha_historico = movimiento.fecha
            $conteosMov = DB::connection('compras')->select("
                SELECT DISTINCT ON (sucursal_id, producto_id, fecha)
                    sucursal_id::text                          AS sucursal_id,
                    producto_id::text                          AS producto_id,
                    m.fecha::text                              AS fecha,
                    (m.detalle->>'total_contado')::numeric     AS conteo_fisico
                FROM movimientos_inventario m
                WHERE m.tipo = 'conteo_fisico'
                  AND m.sucursal_id IN ({$sucIds})
                  AND m.producto_id IN ({$prodIds})
                  AND m.fecha IN ({$fechasQ})
                ORDER BY sucursal_id, producto_id, fecha, created_at DESC
            ");

            $lookup = [];
            foreach ($conteosMov as $c) {
                $lookup[$c->sucursal_id][$c->producto_id][$c->fecha] = $c->conteo_fisico;
            }

            $rows = $rows->map(function ($r) use ($lookup) {
                if (is_null($r->conteo_fisico)) {
                    $val = $lookup[(string) $r->sucursal_id][(string) $r->producto_id][(string) $r->fecha] ?? null;
                    if ($val !== null) {
                        $r->conteo_fisico = $val;
                        $r->diferencia    = (float) $val - (float) ($r->brilo_stock ?? 0);
                    }
                }
                return $r;
            });
        }

        // Fechas únicas ordenadas desc
        $fechas = $rows->pluck('fecha')->unique()->sort()->values()->all();

        // Hora de aplicación del conteo por (sucursal_id, fecha).
        // Todas las sucursales usan la misma lógica: conteo.fecha = historico.fecha.
        // (Las nocturnas ingresan el conteo en la mañana con la fecha del día actual.)
        $sucIdsAll  = implode(',', array_map('intval', $rows->pluck('sucursal_id')->unique()->values()->all()));
        $fechasAll  = implode(',', $rows->pluck('fecha')->unique()->map(fn ($f) => "'" . addslashes((string) $f) . "'")->all());
        $conteoTimes = DB::connection('compras')->select("
            SELECT DISTINCT ON (sucursal_id, fecha)
                sucursal_id::text AS sucursal_id,
                m.fecha::text AS fecha,
                created_at::text AS conteo_at
            FROM movimientos_inventario m
            WHERE m.tipo = 'conteo_fisico'
              AND m.sucursal_id IN ({$sucIdsAll})
              AND m.fecha IN ({$fechasAll})
            ORDER BY sucursal_id, fecha, created_at ASC
        ");
        $conteoAtMap = [];
        foreach ($conteoTimes as $ct) {
            $conteoAtMap[$ct->sucursal_id][$ct->fecha] = $ct->conteo_at;
        }

        // Agrupar por sucursal
        $sucursales = $rows->groupBy('sucursal_id')->map(function ($items, $sucId) use ($fechas, $conteoAtMap) {
            // Productos únicos de esta sucursal
            $productos = $items->unique('producto_id')->map(fn ($r) => [
                'producto_id' => $r->producto_id,
                'codigo'      => $r->producto_codigo,
                'nombre'      => $r->producto_nombre,
                'unidad'      => $r->unidad,
            ])->values()->all();

            // Historico: por fecha → por producto_id
            $historico = $items->groupBy('fecha')->map(function ($fechaItems, $fecha) use ($sucId, $conteoAtMap) {
                $conteoAt = $conteoAtMap[(string) $sucId][(string) $fecha] ?? null;
                return [
                    'fecha'     => $fecha,
                    'sync_at'   => $fechaItems->first()->sync_at,
                    'conteo_at' => $conteoAt,
                    'items'     => $fechaItems->keyBy('producto_id')->map(fn ($r) => [
                        'brilo_stock'   => $r->brilo_stock !== null ? round((float) $r->brilo_stock, 3) : null,
                        'conteo_fisico' => $r->conteo_fisico !== null ? round((float) $r->conteo_fisico, 3) : null,
                        'diferencia'    => $r->diferencia !== null ? round((float) $r->diferencia, 3) : null,
                    ])->all(),
                ];
            })->sortByDesc('fecha')->values()->all();

            return [
                'sucursal_id' => $sucId,
                'productos'   => $productos,
                'historico'   => $historico,
            ];
        })->values()->all();

        return response()->json([
            'sucursales' => $sucursales,
            'fechas'     => $fechas,
        ]);
    }

    private function calcularStockItems(?int $sucursalId): \Illuminate\Support\Collection
    {
        $inventarios = Inventario::with('producto')
            ->where('activo', true)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->get();

        if ($inventarios->isEmpty()) return collect();

        $productoIds = $inventarios->pluck('producto_id')->unique()->all();
        $sucIds      = $inventarios->pluck('sucursal_id')->unique()->all();

        $movimientos = DB::connection('compras')
            ->table('movimientos_inventario')
            ->where('tipo', '!=', 'carga_inicial')
            ->whereIn('sucursal_id', $sucIds)
            ->whereIn('producto_id', $productoIds)
            ->selectRaw('sucursal_id, producto_id, SUM(cantidad_base) as total_base')
            ->groupBy('sucursal_id', 'producto_id')
            ->get()
            ->keyBy(fn ($r) => $r->sucursal_id . '_' . $r->producto_id);

        return $inventarios->map(function ($inv) use ($movimientos) {
            $factor          = max((float) ($inv->producto?->factor_conversion ?? 1), 0.0001);
            $key             = $inv->sucursal_id . '_' . $inv->producto_id;
            $movBase         = (float) ($movimientos[$key]?->total_base ?? 0);
            $stockActualBase = $inv->cantidad_inicial_base + $movBase;
            $stockActual     = $stockActualBase / $factor;

            return (object) [
                'sucursal_id'    => $inv->sucursal_id,
                'producto_id'    => $inv->producto_id,
                'producto_nombre'=> $inv->producto?->nombre,
                'producto_codigo'=> $inv->producto?->codigo,
                'unidad'         => $inv->unidad ?: ($inv->producto?->unidad ?? ''),
                'stock_minimo'   => $inv->stock_minimo !== null ? (float) $inv->stock_minimo : null,
                'brilo_stock'    => $inv->brilo_stock !== null ? (float) $inv->brilo_stock : null,
                'costo'          => (float) ($inv->producto?->costo ?? 0),
                'stock_actual'   => round($stockActual, 4),
            ];
        });
    }
}
