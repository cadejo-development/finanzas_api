<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\Receta;
use App\Models\VentaSemanal;
use App\Models\VentaSemanalDetalle;
use App\Traits\RecetaCostoTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VentasController extends Controller
{
    use RecetaCostoTrait;
    /**
     * GET /api/compras/ventas
     * Lista de cabeceras de ventas semanales (paginado).
     */
    public function index(Request $request): JsonResponse
    {
        $query = VentaSemanal::with('detalles')
            ->orderByDesc('semana_inicio');

        if ($request->filled('sucursal_id')) {
            $query->where('sucursal_id', $request->sucursal_id);
        }

        $ventas = $query->get()->map(fn($v) => [
            'id'             => $v->id,
            'sucursal_id'    => $v->sucursal_id,
            'semana_inicio'  => $v->semana_inicio?->format('Y-m-d'),
            'archivo_nombre' => $v->archivo_nombre,
            'importado_por'  => $v->importado_por,
            'total_items'    => $v->detalles->count(),
            'total_vendido'  => round($v->detalles->sum('total'), 2),
            'created_at'     => $v->created_at?->toISOString(),
        ]);

        return response()->json(['success' => true, 'data' => $ventas]);
    }

    /**
     * GET /api/compras/ventas/{id}
     * Detalle de una venta semanal con todos sus líneas.
     */
    public function show(int $id): JsonResponse
    {
        $venta = VentaSemanal::with('detalles')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'            => $venta->id,
                'sucursal_id'   => $venta->sucursal_id,
                'semana_inicio' => $venta->semana_inicio?->format('Y-m-d'),
                'total_vendido' => round($venta->detalles->sum('total'), 2),
                'detalles'      => $venta->detalles->map(fn($d) => [
                    'producto_codigo'  => $d->producto_codigo,
                    'producto_nombre'  => $d->producto_nombre,
                    'categoria_key'    => $d->categoria_key,
                    'cantidad_vendida' => $d->cantidad_vendida,
                    'precio_unitario'  => $d->precio_unitario,
                    'total'            => $d->total,
                ]),
            ],
        ]);
    }

    /**
     * POST /api/compras/ventas/import
     * Importa ventas desde un archivo xlsx/xls/csv.
     *
     * Formato esperado del archivo:
     * Columna A: Código    B: Producto   C: Categoría
     * Columna D: Cantidad  E: Precio Unitario
     * (La primera fila es cabecera, desde la fila 2 vienen datos)
     */
    public function import(Request $request): JsonResponse
    {
        // TODO: habilitar cuando se instale phpoffice/phpspreadsheet
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad de importación no disponible aún.',
        ], 501);
    }

    /**
     * GET /api/compras/ventas/sugerencia
     * Calcula el promedio de ventas históricas para sugerir cantidades de pedido.
     *
     * Params: sucursal_id, semanas (int, default 4), factor (float, default 1.0)
     */
    public function sugerencia(Request $request): JsonResponse
    {
        $request->validate([
            'sucursal_id' => 'required|integer|min:1',
            'semanas'     => 'integer|min:1|max:52',
            'factor'      => 'numeric|min:0.1|max:10',
        ]);

        $sucursalId = (int) $request->sucursal_id;
        $semanas    = (int) ($request->semanas ?? 4);
        $factor     = (float) ($request->factor ?? 1.0);

        // Obtener las últimas N semanas de ventas para esta sucursal
        $ventasIds = VentaSemanal::where('sucursal_id', $sucursalId)
            ->orderByDesc('semana_inicio')
            ->limit($semanas)
            ->pluck('id');

        if ($ventasIds->isEmpty()) {
            return response()->json([
                'success'     => true,
                'data'        => [],
                'semanas_base' => 0,
                'factor'      => $factor,
                'message'     => 'No hay historial de ventas para esta sucursal.',
            ]);
        }

        // Calcular promedio por producto
        $promedios = VentaSemanalDetalle::whereIn('venta_semanal_id', $ventasIds)
            ->select(
                'producto_codigo',
                'producto_nombre',
                'categoria_key',
                DB::raw('AVG(cantidad_vendida) as promedio_cantidad'),
                DB::raw('AVG(precio_unitario) as promedio_precio'),
                DB::raw('COUNT(*) as semanas_con_datos')
            )
            ->groupBy('producto_codigo', 'producto_nombre', 'categoria_key')
            ->orderBy('producto_codigo')
            ->get()
            ->map(fn($p) => [
                'producto_codigo'     => $p->producto_codigo,
                'producto_nombre'     => $p->producto_nombre,
                'categoria_key'       => $p->categoria_key,
                'promedio_cantidad'   => round((float)$p->promedio_cantidad, 2),
                'cantidad_sugerida'   => round((float)$p->promedio_cantidad * $factor, 2),
                'promedio_precio'     => round((float)$p->promedio_precio, 2),
                'semanas_con_datos'   => (int)$p->semanas_con_datos,
            ]);

        return response()->json([
            'success'      => true,
            'data'         => $promedios,
            'semanas_base' => $ventasIds->count(),
            'factor'       => $factor,
        ]);
    }

    /**
     * GET /api/compras/ventas/pivot
     * Vista de ventas agrupada por plato x día.
     *
     * Params: sucursal_id (req), desde (Y-m-d), hasta (Y-m-d), categoria_key (opt)
     * Devuelve: fechas[], platos[{codigo, nombre, precio_unitario, por_fecha{}, total_qty, total_venta}]
     */
    public function pivot(Request $request): JsonResponse
    {
        $request->validate([
            'sucursal_id'  => 'required|integer|min:1',
            'desde'        => 'nullable|date',
            'hasta'        => 'nullable|date',
            'categoria_key'=> 'nullable|string',
        ]);

        $sucursalId   = (int) $request->sucursal_id;
        $desde        = $request->desde;
        $hasta        = $request->hasta;
        $categoriaKey = $request->categoria_key;

        // IDs de ventas_semanales para esta sucursal en el rango
        $query = VentaSemanal::where('sucursal_id', $sucursalId)
            ->orderBy('semana_inicio');

        if ($desde) $query->where('semana_inicio', '>=', $desde);
        if ($hasta) $query->where('semana_inicio', '<=', $hasta);

        $ventas = $query->get();

        if ($ventas->isEmpty()) {
            return response()->json(['success' => true, 'fechas' => [], 'platos' => []]);
        }

        $ventaIds = $ventas->pluck('id');
        // Mapeo id → fecha
        $fechaPorId = $ventas->pluck('semana_inicio', 'id')->map(fn($d) => $d instanceof \Carbon\Carbon ? $d->format('Y-m-d') : substr($d, 0, 10));
        $fechas     = $fechaPorId->values()->unique()->sort()->values()->toArray();

        // Detalles
        $detallesQuery = VentaSemanalDetalle::whereIn('venta_semanal_id', $ventaIds);
        if ($categoriaKey) {
            $detallesQuery->where('categoria_key', $categoriaKey);
        }
        $detalles = $detallesQuery->get();

        // Agrupar por producto → día
        $platos = [];
        foreach ($detalles as $d) {
            $fecha = $fechaPorId[$d->venta_semanal_id] ?? null;
            if (!$fecha) continue;
            $key = $d->producto_codigo ?: $d->producto_nombre;

            if (!isset($platos[$key])) {
                $platos[$key] = [
                    'codigo'          => $d->producto_codigo,
                    'nombre'          => $d->producto_nombre,
                    'categoria_key'   => $d->categoria_key,
                    'precio_unitario' => round((float) $d->precio_unitario, 2),
                    'por_fecha'       => [],
                    'total_qty'       => 0,
                    'total_venta'     => 0,
                ];
            }

            $qty   = (float) $d->cantidad_vendida;
            $total = (float) $d->total;

            $platos[$key]['por_fecha'][$fecha]  = ($platos[$key]['por_fecha'][$fecha] ?? 0) + $qty;
            $platos[$key]['total_qty']          += $qty;
            $platos[$key]['total_venta']        += $total;
            // Actualizar precio_unitario al promedio ponderado simple
            $platos[$key]['precio_unitario'] = round(
                $platos[$key]['total_qty'] > 0 ? $platos[$key]['total_venta'] / $platos[$key]['total_qty'] : 0, 2
            );
        }

        // Ordenar por total_venta desc y redondear
        usort($platos, fn($a, $b) => $b['total_venta'] <=> $a['total_venta']);
        $platos = array_map(function ($p) {
            $p['total_qty']   = round($p['total_qty'], 2);
            $p['total_venta'] = round($p['total_venta'], 2);
            return $p;
        }, array_values($platos));

        // ── Food cost: usa la misma lógica que la página de Recetas ─────────
        $codigos = array_filter(array_column($platos, 'codigo'));
        if (!empty($codigos)) {
            $recetas = Receta::on('compras')
                ->whereIn('codigo_origen', $codigos)
                ->where('activa', true)
                ->with([
                    'ingredientes.producto',
                    'ingredientes.subReceta.productoAsociado',
                    'ingredientes.subReceta.ingredientes.producto',
                    'modificadores.producto',
                ])
                ->get()
                ->keyBy('codigo_origen');

            $platos = array_map(function ($p) use ($recetas) {
                $receta = $recetas[$p['codigo']] ?? null;
                if (!$receta) {
                    $p['costo_receta']  = null;
                    $p['pct_food_cost'] = null;
                    return $p;
                }

                // Ingredientes (igual que RecetasController::costos)
                $costoIngredientes = (float) $receta->ingredientes->sum(function ($ing) {
                    if ($ing->sub_receta_id && $ing->subReceta) {
                        return (float) $ing->cantidad_por_plato
                            * $this->calcularCostoSubReceta($ing->subReceta, $ing->unidad);
                    }
                    if ($ing->producto) {
                        return (float) $ing->cantidad_por_plato
                            * $this->costoPorUnidadReceta($ing->producto, strtolower(trim($ing->unidad ?? '')));
                    }
                    return 0.0;
                });

                // Modificadores: max por grupo (cliente elige UNO por grupo)
                $gruposMax = [];
                foreach ($receta->modificadores as $mod) {
                    if (!$mod->producto) continue;
                    $costoUnit = $this->costoPorUnidadReceta($mod->producto, strtolower(trim($mod->unidad ?? '')));
                    $costoOp   = $costoUnit * (float) ($mod->cantidad ?? 0);
                    $gKey      = $mod->grupo_codigo ?: ($mod->grupo_nombre ?: 'default');
                    $gruposMax[$gKey] = max($gruposMax[$gKey] ?? 0.0, $costoOp);
                }
                $costoModificadores = (float) array_sum($gruposMax);

                $costoPlato    = $costoIngredientes + $costoModificadores;
                $precioVenta   = (float) ($receta->precio ?: $p['precio_unitario']);
                $precioSinIva  = $precioVenta / 1.13;
                $p['costo_receta']  = round($costoPlato, 4);
                $p['pct_food_cost'] = $precioSinIva > 0 ? round(($costoPlato / $precioSinIva) * 100, 1) : null;
                return $p;
            }, $platos);
        }

        return response()->json([
            'success' => true,
            'fechas'  => $fechas,
            'platos'  => $platos,
        ]);
    }

    /**
     * GET /api/compras/ventas/consumo-ingredientes
     * Agrega consumo total de ingredientes directos de todos los platos vendidos en el período.
     *
     * Params: sucursal_id, desde, hasta, categoria_key (opt)
     */
    public function consumoIngredientes(Request $request): JsonResponse
    {
        $request->validate([
            'sucursal_id'   => 'required|integer|min:1',
            'desde'         => 'nullable|date',
            'hasta'         => 'nullable|date',
            'categoria_key' => 'nullable|string',
        ]);

        $sucursalId   = (int) $request->sucursal_id;
        $desde        = $request->desde ?? '2000-01-01';
        $hasta        = $request->hasta ?? '2099-12-31';
        $categoriaKey = $request->categoria_key;

        $catWhere = $categoriaKey ? 'AND vsd.categoria_key = ?' : '';
        // Parámetros base: [sucursal, desde, hasta, (categoria?)]
        $base = $categoriaKey
            ? [$sucursalId, $desde, $hasta, $categoriaKey]
            : [$sucursalId, $desde, $hasta];
        // La query usa UNION ALL, por lo que los parámetros se repiten dos veces
        $bindings = array_merge($base, $base);

        // Factor para convertir unidad_receta → unidad del catálogo (p.unidad).
        // Se evalúa por fila dentro del SUM, así distintas recetas que usen
        // unidades diferentes (oz fl, lt, ml…) para el mismo producto se consolidan
        // en una sola fila ya normalizada a la unidad del catálogo.
        $convCase = "CASE
            WHEN LOWER(l.unidad_receta) = LOWER(p.unidad) THEN 1
            WHEN p.factor_conversion IS NOT NULL AND p.unidad_base IS NOT NULL
             AND LOWER(l.unidad_receta) = LOWER(p.unidad_base) THEN 1.0/p.factor_conversion
            WHEN LOWER(l.unidad_receta) IN ('oz fl','fl oz') AND LOWER(p.unidad) IN ('l','lt','lts') THEN 0.0295735
            WHEN LOWER(l.unidad_receta) IN ('oz fl','fl oz') AND LOWER(p.unidad) = 'ml'              THEN 29.5735
            WHEN LOWER(l.unidad_receta)='oz'  AND LOWER(p.unidad)='lb'                THEN 1.0/16
            WHEN LOWER(l.unidad_receta)='lb'  AND LOWER(p.unidad)='oz'                THEN 16
            WHEN LOWER(l.unidad_receta)='oz'  AND LOWER(p.unidad)='kg'                THEN 0.0283495
            WHEN LOWER(l.unidad_receta)='lb'  AND LOWER(p.unidad)='kg'                THEN 0.453592
            WHEN LOWER(l.unidad_receta)='g'   AND LOWER(p.unidad)='kg'                THEN 1.0/1000
            WHEN LOWER(l.unidad_receta)='kg'  AND LOWER(p.unidad)='g'                 THEN 1000
            WHEN LOWER(l.unidad_receta)='g'   AND LOWER(p.unidad)='lb'                THEN 1.0/453.592
            WHEN LOWER(l.unidad_receta)='lb'  AND LOWER(p.unidad)='g'                 THEN 453.592
            WHEN LOWER(l.unidad_receta)='ml'  AND LOWER(p.unidad) IN ('l','lt','lts') THEN 1.0/1000
            WHEN LOWER(l.unidad_receta) IN ('l','lt','lts') AND LOWER(p.unidad)='ml'  THEN 1000
            WHEN LOWER(l.unidad_receta)='cup'  AND LOWER(p.unidad) IN ('l','lt','lts') THEN 0.236588
            WHEN LOWER(l.unidad_receta)='cups' AND LOWER(p.unidad) IN ('l','lt','lts') THEN 0.236588
            WHEN LOWER(l.unidad_receta)='tsp'  AND LOWER(p.unidad) IN ('l','lt','lts') THEN 0.00492892
            WHEN LOWER(l.unidad_receta)='tbsp' AND LOWER(p.unidad) IN ('l','lt','lts') THEN 0.0147868
            ELSE 1 END";

        $rows = DB::connection('compras')->select("
            SELECT
                p.nombre             AS ingrediente,
                p.codigo             AS ingrediente_codigo,
                p.unidad             AS unidad_receta,
                p.unidad             AS unidad_compra,
                p.unidad_base,
                p.factor_conversion,
                COALESCE(p.costo,0)  AS costo_unitario,
                ROUND(SUM(l.cantidad_usada * ({$convCase}))::numeric, 3) AS total_consumido,
                ROUND(SUM(l.cantidad_usada * COALESCE(p.costo,0) * ({$convCase}))::numeric, 2) AS costo_total,
                COUNT(DISTINCT l.plato_codigo) AS en_platos,
                STRING_AGG(DISTINCT l.plato_nombre, ', ' ORDER BY l.plato_nombre) AS platos_que_lo_usan
            FROM (
                -- Ingredientes directos del plato
                SELECT
                    ri.producto_id,
                    ri.unidad                                       AS unidad_receta,
                    ri.cantidad_por_plato * vsd.cantidad_vendida    AS cantidad_usada,
                    r.codigo_origen                                  AS plato_codigo,
                    r.nombre                                         AS plato_nombre
                FROM ventas_semanales vs
                JOIN ventas_semanales_detalle vsd ON vsd.venta_semanal_id = vs.id
                JOIN recetas r   ON r.codigo_origen = vsd.producto_codigo AND r.activa = true
                JOIN receta_ingredientes ri ON ri.receta_id = r.id AND ri.producto_id IS NOT NULL
                WHERE vs.sucursal_id = ? AND vs.semana_inicio >= ? AND vs.semana_inicio <= ?
                {$catWhere}

                UNION ALL

                -- Ingredientes de sub-recetas (nivel 1)
                SELECT
                    ri2.producto_id,
                    ri2.unidad                                                       AS unidad_receta,
                    ri.cantidad_por_plato * ri2.cantidad_por_plato * vsd.cantidad_vendida AS cantidad_usada,
                    r.codigo_origen                                                   AS plato_codigo,
                    r.nombre                                                          AS plato_nombre
                FROM ventas_semanales vs
                JOIN ventas_semanales_detalle vsd ON vsd.venta_semanal_id = vs.id
                JOIN recetas r    ON r.codigo_origen  = vsd.producto_codigo AND r.activa = true
                JOIN receta_ingredientes ri  ON ri.receta_id   = r.id   AND ri.sub_receta_id IS NOT NULL
                JOIN recetas sr              ON sr.id           = ri.sub_receta_id AND sr.activa = true
                JOIN receta_ingredientes ri2 ON ri2.receta_id  = sr.id  AND ri2.producto_id IS NOT NULL
                WHERE vs.sucursal_id = ? AND vs.semana_inicio >= ? AND vs.semana_inicio <= ?
                {$catWhere}
            ) l
            JOIN productos p ON p.id = l.producto_id
            GROUP BY p.id, p.nombre, p.codigo, p.unidad, p.unidad_base, p.factor_conversion, p.costo
            ORDER BY costo_total DESC
        ", $bindings);

        // La conversión ya se aplicó en SQL; total_consumido siempre está en p.unidad (catálogo).
        $ingredientes = array_map(function ($r) {
            return [
                'ingrediente'         => $r->ingrediente,
                'codigo'              => $r->ingrediente_codigo,
                'unidad_receta'       => $r->unidad_receta,   // = unidad catálogo
                'unidad_compra'       => $r->unidad_compra,
                'unidades_difieren'   => false,
                'total_consumido'     => (float) $r->total_consumido,
                'total_en_compra'     => null,
                'costo_unitario'      => round((float) $r->costo_unitario, 4),
                'costo_total'         => (float) $r->costo_total,
                'en_platos'           => (int) $r->en_platos,
                'platos_que_lo_usan'  => $r->platos_que_lo_usan,
            ];
        }, $rows);

        return response()->json([
            'success'      => true,
            'ingredientes' => $ingredientes,
        ]);
    }

    /**
     * GET /api/compras/ventas/consumo-receta
     * Ingredientes de una receta multiplicados por la cantidad vendida.
     *
     * Params: codigo (codigo_origen), cantidad (unidades vendidas)
     */
    public function consumoReceta(Request $request): JsonResponse
    {
        $request->validate([
            'codigo'   => 'required|string',
            'cantidad' => 'required|numeric|min:0',
        ]);

        $codigo   = trim($request->codigo);
        $cantidad = (float) $request->cantidad;

        $receta = DB::connection('compras')
            ->table('recetas')
            ->where('codigo_origen', $codigo)
            ->where('activa', true)
            ->first();

        if (!$receta) {
            return response()->json([
                'success'   => false,
                'encontrada'=> false,
                'message'   => 'No hay receta registrada para este plato.',
            ]);
        }

        $ingredientes = DB::connection('compras')
            ->table('receta_ingredientes as ri')
            ->leftJoin('productos as p',  'p.id',  '=', 'ri.producto_id')
            ->leftJoin('recetas as sr',   'sr.id', '=', 'ri.sub_receta_id')
            ->where('ri.receta_id', $receta->id)
            ->select(
                'ri.cantidad_por_plato',
                'ri.unidad',
                'p.nombre  as ingrediente_nombre',
                'sr.nombre as sub_receta_nombre'
            )
            ->get()
            ->map(fn($i) => [
                'nombre'          => $i->ingrediente_nombre ?? $i->sub_receta_nombre ?? '—',
                'tipo'            => $i->sub_receta_nombre ? 'sub_receta' : 'ingrediente',
                'cantidad_plato'  => round((float) $i->cantidad_por_plato, 4),
                'unidad'          => $i->unidad,
                'total_consumido' => round((float) $i->cantidad_por_plato * $cantidad, 3),
            ]);

        return response()->json([
            'success'    => true,
            'encontrada' => true,
            'receta'     => [
                'id'             => $receta->id,
                'nombre'         => $receta->nombre,
                'codigo_origen'  => $receta->codigo_origen,
            ],
            'cantidad_vendida' => $cantidad,
            'ingredientes'     => $ingredientes,
        ]);
    }

    /**
     * GET /api/compras/ventas/proyeccion
     * Proyección de ventas por plato para un período futuro usando fórmula de 6 niveles.
     *
     * Params: sucursal_id (req), desde (Y-m-d, default mañana), hasta (Y-m-d, default desde+9), categoria_key (opt)
     *
     * Fórmula: P = blend ponderado de 5 componentes × Fe (eventos)
     *   L1 35% — Histórico mismo período año anterior
     *   L2 25% — Histórico × factor crecimiento sucursal (90d)
     *   L3 15% — Histórico × factor crecimiento plato (120d)
     *   L4 15% — Tendencia reciente (avg 10d×50% + 30d×30% + 60d×20%)
     *   L5 10% — Mejor estimado × factor eventos
     *
     * Buffer: pedido_sugerido = P × 1.10 (siempre), × 1.05 adicional en vacaciones
     */
    public function proyeccion(Request $request): JsonResponse
    {
        $request->validate([
            'sucursal_id'   => 'required|integer|min:1',
            'desde'         => 'nullable|date',
            'hasta'         => 'nullable|date',
            'categoria_key' => 'nullable|string',
        ]);

        $sucursalId   = (int) $request->sucursal_id;
        $categoriaKey = $request->categoria_key;

        // Período de proyección (default: próximos 10 días)
        $desde = $request->desde ?? date('Y-m-d', strtotime('tomorrow'));
        $hasta = $request->hasta ?? date('Y-m-d', strtotime($desde . ' +9 days'));

        $diasPeriodo = max(1, (int) round((strtotime($hasta) - strtotime($desde)) / 86400) + 1);

        // Día de referencia: último día con datos reales (día anterior a la proyección)
        $ref = date('Y-m-d', strtotime($desde) - 86400);

        // ── L1: Histórico — mismo período año anterior ────────────────────
        $desdeAnt = date('Y-m-d', strtotime($desde . ' -1 year'));
        $hastaAnt = date('Y-m-d', strtotime($hasta . ' -1 year'));
        $historico = $this->vpGetPorPlato($sucursalId, $desdeAnt, $hastaAnt, $categoriaKey);

        // ── L2: Factor sucursal — crecimiento últimos 90d ─────────────────
        $suc90Desde    = date('Y-m-d', strtotime($ref . ' -90 days'));
        $suc90AntDesde = date('Y-m-d', strtotime($suc90Desde . ' -1 year'));
        $suc90AntHasta = date('Y-m-d', strtotime($ref . ' -1 year'));

        $sucTotal    = $this->vpTotalQty($sucursalId, $suc90Desde, $ref);
        $sucTotalAnt = $this->vpTotalQty($sucursalId, $suc90AntDesde, $suc90AntHasta);
        $Fs          = $sucTotalAnt > 0 ? max(0.3, min(3.0, $sucTotal / $sucTotalAnt)) : 1.0;

        // ── L3: Factor plato — crecimiento últimos 120d ───────────────────
        $p120Desde    = date('Y-m-d', strtotime($ref . ' -120 days'));
        $p120AntDesde = date('Y-m-d', strtotime($p120Desde . ' -1 year'));
        $p120AntHasta = date('Y-m-d', strtotime($ref . ' -1 year'));

        $platos120Act = $this->vpGetPorPlato($sucursalId, $p120Desde, $ref, $categoriaKey);
        $platos120Ant = $this->vpGetPorPlato($sucursalId, $p120AntDesde, $p120AntHasta, $categoriaKey);

        // ── L4: Tendencia reciente ────────────────────────────────────────
        $avg10d = $this->vpAvgDiario($sucursalId, date('Y-m-d', strtotime($ref . ' -9 days')),   $ref, $categoriaKey);
        $avg30d = $this->vpAvgDiario($sucursalId, date('Y-m-d', strtotime($ref . ' -29 days')),  $ref, $categoriaKey);
        $avg60d = $this->vpAvgDiario($sucursalId, date('Y-m-d', strtotime($ref . ' -59 days')),  $ref, $categoriaKey);

        // ── L6: Factor eventos ────────────────────────────────────────────
        [$Fe, $detalleEventos] = $this->vpFactorEventos($desde, $hasta);

        // ── Proyección por plato ──────────────────────────────────────────
        $codigos = array_unique(array_merge(
            array_keys($historico),
            array_keys($platos120Act),
            array_keys($avg10d),
        ));

        $proyecciones = [];

        foreach ($codigos as $cod) {
            // L1
            $H = $historico[$cod]['qty'] ?? 0;

            // L3: factor plato clamped
            $qAct = $platos120Act[$cod]['qty'] ?? 0;
            $qAnt = $platos120Ant[$cod]['qty'] ?? 0;
            $Fp   = $qAnt > 0 ? max(0.3, min(3.0, $qAct / $qAnt)) : 1.0;

            // L4: tendencia proyectada al período
            $d10 = ($avg10d[$cod]['avg'] ?? 0) * $diasPeriodo;
            $d30 = ($avg30d[$cod]['avg'] ?? 0) * $diasPeriodo;
            $d60 = ($avg60d[$cod]['avg'] ?? 0) * $diasPeriodo;
            $Ft  = $d10 * 0.50 + $d30 * 0.30 + $d60 * 0.20;

            // Blend 5 componentes
            $P1 = $H;              // histórico puro
            $P2 = $H * $Fs;       // histórico × crecimiento sucursal
            $P3 = $H * $Fp;       // histórico × crecimiento plato
            $P4 = $Ft;            // tendencia reciente
            $P5 = max($P1, $P2, $P3, $P4) * $Fe; // mejor estimado × eventos

            $P = 0.35 * $P1 + 0.25 * $P2 + 0.15 * $P3 + 0.15 * $P4 + 0.10 * $P5;
            $P = max(0.0, $P);

            if ($P < 0.5) continue; // descartar productos insignificantes

            // Buffer +10% base; +5% adicional si hay vacaciones en el período
            $bufferVac = in_array('vacaciones_escolares', $detalleEventos) ? 1.05 : 1.0;
            $pedido    = $P * 1.10 * $bufferVac;

            $nombre    = $historico[$cod]['nombre']    ?? ($platos120Act[$cod]['nombre'] ?? $cod);
            $categoria = $historico[$cod]['categoria'] ?? ($platos120Act[$cod]['categoria'] ?? null);

            $proyecciones[] = [
                'codigo'         => (string) $cod,
                'nombre'         => $nombre,
                'categoria_key'  => $categoria,
                'qty_proyectada' => round($P, 1),
                'qty_pedido'     => round($pedido, 1),
                'factores'       => [
                    'H'  => round($H, 1),
                    'Fs' => round($Fs, 3),
                    'Fp' => round($Fp, 3),
                    'Ft' => round($Ft, 1),
                    'Fe' => round($Fe, 3),
                ],
            ];
        }

        usort($proyecciones, fn($a, $b) => $b['qty_proyectada'] <=> $a['qty_proyectada']);

        return response()->json([
            'success'         => true,
            'sucursal_id'     => $sucursalId,
            'desde'           => $desde,
            'hasta'           => $hasta,
            'dias'            => $diasPeriodo,
            'referencia'      => $ref,
            'factor_sucursal' => round($Fs, 3),
            'factor_eventos'  => round($Fe, 3),
            'eventos'         => $detalleEventos,
            'proyecciones'    => $proyecciones,
        ]);
    }

    /**
     * GET /api/compras/ventas/proyeccion-ingredientes
     * Traduce la proyección de platos del siguiente período a cantidades de ingredientes,
     * e incluye el último conteo físico de este mes por producto.
     *
     * Params: sucursal_id (req), desde (Y-m-d), hasta (Y-m-d), categoria_key (opt)
     * Devuelve: ingredientes[{producto_id, codigo, nombre, unidad, qty_proyectada, conteo_fisico, total_a_pedir}]
     */
    public function proyeccionIngredientes(Request $request): JsonResponse
    {
        $request->validate([
            'sucursal_id'   => 'required|integer|min:1',
            'desde'         => 'nullable|date',
            'hasta'         => 'nullable|date',
            'categoria_key' => 'nullable|string',
        ]);

        $sucursalId   = (int) $request->sucursal_id;
        $categoriaKey = $request->categoria_key;
        $desde        = $request->desde ?? date('Y-m-d', strtotime('tomorrow'));
        $hasta        = $request->hasta ?? date('Y-m-d', strtotime($desde . ' +9 days'));

        // ── 1. Obtener proyección de platos ───────────────────────────────
        $proyReq = clone $request;
        $proyReq->merge(['sucursal_id' => $sucursalId, 'desde' => $desde, 'hasta' => $hasta, 'categoria_key' => $categoriaKey]);
        $proyResp = $this->proyeccion($proyReq);
        $proyData = json_decode($proyResp->getContent(), true);

        if (empty($proyData['proyecciones'])) {
            return response()->json([
                'success'      => true,
                'sucursal_id'  => $sucursalId,
                'desde'        => $desde,
                'hasta'        => $hasta,
                'ingredientes' => [],
            ]);
        }

        // Map plato_codigo → qty_proyectada
        $qtyMap = [];
        foreach ($proyData['proyecciones'] as $p) {
            if (!empty($p['codigo'])) {
                $qtyMap[$p['codigo']] = (float) $p['qty_proyectada'];
            }
        }

        $codigos = array_keys($qtyMap);

        // ── 2. Expandir recetas → ingredientes ────────────────────────────────
        [$mapa, ] = $this->_expandRecetasIngredientes($qtyMap);

        if (empty($mapa)) {
            return response()->json([
                'success'      => true,
                'sucursal_id'  => $sucursalId,
                'desde'        => $desde,
                'hasta'        => $hasta,
                'ingredientes' => [],
            ]);
        }

        // ── 3. Último conteo físico del mes en curso para estos productos ──
        $mesInicio = date('Y-m-01');
        $mesFin    = date('Y-m-t');

        $conteos = DB::connection('compras')
            ->table('movimientos_inventario as m')
            ->where('m.sucursal_id', $sucursalId)
            ->where('m.tipo', 'conteo_fisico')
            ->whereBetween('m.fecha', [$mesInicio, $mesFin])
            ->whereIn('m.producto_id', array_keys($mapa))
            ->select('m.producto_id', 'm.detalle', DB::raw('MAX(m.fecha) as ultima_fecha'))
            ->groupBy('m.producto_id', 'm.detalle')
            ->orderByRaw('MAX(m.fecha) DESC')
            ->get()
            // Quedarse solo con el registro más reciente por producto
            ->groupBy('producto_id')
            ->map(fn($rows) => $rows->first());

        // ── 4. Construir respuesta ────────────────────────────────────────
        $ingredientes = array_values(array_map(function ($ing) use ($conteos) {
            $ing['qty_proyectada'] = round($ing['qty_proyectada'], 3);

            $detalle = $conteos->get($ing['producto_id']);
            $totalContado = null;
            if ($detalle) {
                $det = is_string($detalle->detalle) ? json_decode($detalle->detalle, true) : (array) $detalle->detalle;
                $totalContado = (float) ($det['total_contado'] ?? 0);
            }
            $ing['conteo_fisico']  = $totalContado;
            $ing['total_a_pedir']  = $totalContado !== null
                ? round(max(0, $ing['qty_proyectada'] - $totalContado), 3)
                : round($ing['qty_proyectada'], 3);

            return $ing;
        }, $mapa));

        // Ordenar por qty_proyectada desc
        usort($ingredientes, fn($a, $b) => $b['qty_proyectada'] <=> $a['qty_proyectada']);

        return response()->json([
            'success'      => true,
            'sucursal_id'  => $sucursalId,
            'desde'        => $desde,
            'hasta'        => $hasta,
            'dias'         => $proyData['dias'],
            'eventos'      => $proyData['eventos'] ?? [],
            'ingredientes' => $ingredientes,
        ]);
    }

    /**
     * GET /api/compras/ventas/proyeccion-ingrediente-detalle
     * Desglose por plato de cuánto contribuye cada uno a la proyección de un ingrediente.
     * Params: sucursal_id, desde, hasta, codigo (código del producto/ingrediente)
     */
    public function proyeccionIngredienteDetalle(Request $request): JsonResponse
    {
        $request->validate([
            'sucursal_id' => 'required|integer|min:1',
            'desde'       => 'nullable|date',
            'hasta'       => 'nullable|date',
            'codigo'      => 'required|string',
        ]);

        $sucursalId = (int) $request->sucursal_id;
        $desde      = $request->desde ?? date('Y-m-d', strtotime('tomorrow'));
        $hasta      = $request->hasta ?? date('Y-m-d', strtotime($desde . ' +9 days'));
        $codigo     = trim($request->codigo);

        // 1. Resolver producto por código
        $producto = DB::connection('compras')
            ->table('productos')
            ->where('codigo', $codigo)
            ->select('id', 'nombre', 'unidad', 'codigo')
            ->first();

        if (!$producto) {
            return response()->json(['success' => false, 'message' => 'Ingrediente no encontrado'], 404);
        }

        $productoId = $producto->id;

        // 2. Proyección de platos
        $proyReq = clone $request;
        $proyReq->merge(['sucursal_id' => $sucursalId, 'desde' => $desde, 'hasta' => $hasta]);
        $proyData = json_decode($this->proyeccion($proyReq)->getContent(), true);

        if (empty($proyData['proyecciones'])) {
            return response()->json([
                'success'     => true,
                'ingrediente' => ['codigo' => $codigo, 'nombre' => $producto->nombre, 'unidad' => $producto->unidad],
                'desde'       => $desde, 'hasta' => $hasta, 'dias' => 10,
                'platos'      => [], 'total' => 0,
            ]);
        }

        $qtyMap     = [];
        $platosData = [];
        foreach ($proyData['proyecciones'] as $p) {
            if (!empty($p['codigo'])) {
                $qtyMap[$p['codigo']]     = (float) $p['qty_proyectada'];
                $platosData[$p['codigo']] = $p;
            }
        }

        // 3. Expandir recetas usando el mismo cálculo centralizado
        [, $filas] = $this->_expandRecetasIngredientes($qtyMap);

        // 4. Filtrar filas del ingrediente solicitado y construir desglose
        $platos = [];
        foreach ($filas as $fila) {
            if ($fila['producto_id'] !== $productoId) continue;
            $pData = $platosData[$fila['plato_codigo']] ?? [];
            $platos[] = [
                'plato_codigo'     => $fila['plato_codigo'],
                'plato_nombre'     => $pData['nombre'] ?? $fila['plato_nombre'],
                'categoria_key'    => $pData['categoria_key'] ?? null,
                'via_sub_receta'   => $fila['sub_nombre'],
                'qty_plato'        => round($fila['qty_plato'], 1),
                'qty_pedido_plato' => round((float) ($pData['qty_pedido'] ?? 0), 1),
                'cant_por_plato'   => round($fila['cant_por_plato'], 4),
                'unidad_receta'    => $fila['unidad_receta'],
                'contribucion'     => round($fila['contribucion'], 3),
                'pct'              => 0,
            ];
        }

        usort($platos, fn($a, $b) => $b['contribucion'] <=> $a['contribucion']);

        $total = array_sum(array_column($platos, 'contribucion'));
        if ($total > 0) {
            foreach ($platos as &$p) {
                $p['pct'] = round($p['contribucion'] / $total * 100, 1);
            }
            unset($p);
        }

        return response()->json([
            'success'         => true,
            'ingrediente'     => [
                'codigo' => $codigo,
                'nombre' => $producto->nombre,
                'unidad' => $producto->unidad,
            ],
            'desde'           => $desde,
            'hasta'           => $hasta,
            'dias'            => (int) ($proyData['dias'] ?? 10),
            'eventos'         => $proyData['eventos'] ?? [],
            'factor_sucursal' => round((float) ($proyData['factor_sucursal'] ?? 1), 3),
            'factor_eventos'  => round((float) ($proyData['factor_eventos'] ?? 1), 3),
            'platos'          => $platos,
            'total'           => round($total, 3),
        ]);
    }

    // ─── Cálculo centralizado de ingredientes ─────────────────────────────────

    /**
     * Expande un mapa plato_codigo→qty a ingredientes con conversión de unidades aplicada por fila.
     * Única fuente de verdad para proyeccionIngredientes y proyeccionIngredienteDetalle.
     *
     * @param  array $qtyMap  [plato_codigo => qty_proyectada]
     * @return array  [$mapa, $filas]
     *   $mapa  = [producto_id => ['codigo', 'nombre', 'unidad', 'qty_proyectada', 'costo', ...]]
     *   $filas = [['producto_id', 'plato_codigo', 'plato_nombre', 'sub_nombre',
     *              'cant_por_plato', 'unidad_receta', 'qty_plato', 'contribucion'], ...]
     */
    private function _expandRecetasIngredientes(array $qtyMap): array
    {
        if (empty($qtyMap)) return [[], []];

        $codigos = array_keys($qtyMap);

        $unitConv = [
            'oz fl|lt'  => 0.0295735,  'oz fl|ml'  => 29.5735,
            'fl oz|lt'  => 0.0295735,  'fl oz|ml'  => 29.5735,
            'oz|g'      => 28.3495,    'oz|kg'     => 0.0283495,
            'ml|lt'     => 0.001,      'lt|ml'     => 1000.0,
            'g|kg'      => 0.001,      'kg|g'      => 1000.0,
            'lb|kg'     => 0.453592,   'lb|g'      => 453.592,
            'cup|lt'    => 0.236588,   'cups|lt'   => 0.236588,
            'tsp|lt'    => 0.00492892, 'tbsp|lt'   => 0.0147868,
        ];

        // Ingredientes directos (producto_id enlazado directamente)
        $directos = DB::connection('compras')
            ->table('recetas as r')
            ->join('receta_ingredientes as ri', 'ri.receta_id', '=', 'r.id')
            ->join('productos as p', 'p.id', '=', 'ri.producto_id')
            ->whereIn('r.codigo_origen', $codigos)
            ->where('r.activa', true)
            ->whereNotNull('ri.producto_id')
            ->select(
                'r.codigo_origen as plato_codigo', 'r.nombre as plato_nombre_receta',
                'p.id as producto_id', 'p.nombre', 'p.codigo as prod_codigo', 'p.unidad', 'p.costo',
                'ri.cantidad_por_plato', 'ri.unidad as unidad_receta',
                DB::raw("NULL::text as sub_nombre")
            )
            ->get();

        // Sub-recetas CP: son materias primas — se proyectan como producto, no se expanden
        $subRecetasCP = DB::connection('compras')
            ->table('recetas as r')
            ->join('receta_ingredientes as ri', 'ri.receta_id', '=', 'r.id')
            ->join('recetas as sr', 'sr.id', '=', 'ri.sub_receta_id')
            ->join('productos as p', 'p.codigo', '=', 'sr.codigo_origen')
            ->whereIn('r.codigo_origen', $codigos)
            ->where('r.activa', true)
            ->where('sr.activa', true)
            ->where('sr.codigo_origen', 'like', 'CP%')
            ->select(
                'r.codigo_origen as plato_codigo', 'r.nombre as plato_nombre_receta',
                'p.id as producto_id', 'p.nombre', 'p.codigo as prod_codigo', 'p.unidad', 'p.costo',
                'ri.cantidad_por_plato', 'ri.unidad as unidad_receta',
                'sr.nombre as sub_nombre'
            )
            ->get();

        // Sub-recetas regulares (no CP): se expanden en sus ingredientes
        $subRecetas = DB::connection('compras')
            ->table('recetas as r')
            ->join('receta_ingredientes as ri', 'ri.receta_id', '=', 'r.id')
            ->join('recetas as sr', 'sr.id', '=', 'ri.sub_receta_id')
            ->join('receta_ingredientes as ri2', 'ri2.receta_id', '=', 'sr.id')
            ->join('productos as p', 'p.id', '=', 'ri2.producto_id')
            ->whereIn('r.codigo_origen', $codigos)
            ->where('r.activa', true)
            ->where('sr.activa', true)
            ->where('sr.codigo_origen', 'not like', 'CP%')
            ->whereNotNull('ri2.producto_id')
            ->select(
                'r.codigo_origen as plato_codigo', 'r.nombre as plato_nombre_receta',
                'p.id as producto_id', 'p.nombre', 'p.codigo as prod_codigo', 'p.unidad', 'p.costo',
                DB::raw('ri.cantidad_por_plato * ri2.cantidad_por_plato AS cantidad_por_plato'),
                'ri2.unidad as unidad_receta', 'sr.nombre as sub_nombre'
            )
            ->get();

        $mapa  = [];
        $filas = [];

        foreach (array_merge($directos->all(), $subRecetasCP->all(), $subRecetas->all()) as $row) {
            $qty    = $qtyMap[$row->plato_codigo] ?? 0;
            $pid    = $row->producto_id;
            $fromU  = strtolower(trim($row->unidad_receta ?? ''));
            $toU    = strtolower(trim($row->unidad ?? ''));
            $factor = ($fromU !== $toU && $fromU !== '' && $toU !== '')
                      ? ($unitConv["$fromU|$toU"] ?? 1.0)
                      : 1.0;

            $contrib   = (float) $row->cantidad_por_plato * $qty * $factor;
            $unidFinal = $toU !== '' ? $toU : $fromU;

            if (!isset($mapa[$pid])) {
                $mapa[$pid] = [
                    'producto_id'    => $pid,
                    'codigo'         => $row->prod_codigo,
                    'nombre'         => $row->nombre,
                    'unidad'         => $unidFinal,
                    'qty_proyectada' => 0.0,
                    'costo'          => (float) ($row->costo ?? 0),
                ];
            }
            $mapa[$pid]['qty_proyectada'] += $contrib;

            $filas[] = [
                'producto_id'   => $pid,
                'plato_codigo'  => $row->plato_codigo,
                'plato_nombre'  => $row->plato_nombre_receta ?? $row->plato_codigo,
                'sub_nombre'    => $row->sub_nombre ?? null,
                'cant_por_plato'=> (float) $row->cantidad_por_plato,
                'unidad_receta' => $row->unidad_receta,
                'qty_plato'     => $qty,
                'contribucion'  => $contrib,
            ];
        }

        return [$mapa, $filas];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Qty total vendida por plato en un rango, keyed by producto_codigo. */
    private function vpGetPorPlato(int $suc, string $desde, string $hasta, ?string $cat): array
    {
        $q = DB::connection('compras')
            ->table('ventas_semanales as vs')
            ->join('ventas_semanales_detalle as d', 'd.venta_semanal_id', '=', 'vs.id')
            ->where('vs.sucursal_id', $suc)
            ->whereBetween('vs.semana_inicio', [$desde, $hasta])
            ->select('d.producto_codigo as cod', 'd.producto_nombre as nom', 'd.categoria_key as cat',
                     DB::raw('SUM(d.cantidad_vendida) as qty'))
            ->groupBy('d.producto_codigo', 'd.producto_nombre', 'd.categoria_key');

        if ($cat) $q->where('d.categoria_key', $cat);

        return $q->get()->keyBy('cod')->map(fn($r) => [
            'nombre'    => $r->nom,
            'categoria' => $r->cat,
            'qty'       => (float) $r->qty,
        ])->toArray();
    }

    /** Qty total de todos los platos sumada (para factor sucursal). */
    private function vpTotalQty(int $suc, string $desde, string $hasta): float
    {
        return (float) DB::connection('compras')
            ->table('ventas_semanales as vs')
            ->join('ventas_semanales_detalle as d', 'd.venta_semanal_id', '=', 'vs.id')
            ->where('vs.sucursal_id', $suc)
            ->whereBetween('vs.semana_inicio', [$desde, $hasta])
            ->sum('d.cantidad_vendida');
    }

    /** Promedio diario de qty por plato en un rango, keyed by producto_codigo. */
    private function vpAvgDiario(int $suc, string $desde, string $hasta, ?string $cat): array
    {
        $dias = max(1, (int) round((strtotime($hasta) - strtotime($desde)) / 86400) + 1);

        $q = DB::connection('compras')
            ->table('ventas_semanales as vs')
            ->join('ventas_semanales_detalle as d', 'd.venta_semanal_id', '=', 'vs.id')
            ->where('vs.sucursal_id', $suc)
            ->whereBetween('vs.semana_inicio', [$desde, $hasta])
            ->select('d.producto_codigo as cod', 'd.producto_nombre as nom', 'd.categoria_key as cat',
                     DB::raw('SUM(d.cantidad_vendida) as qty_total'))
            ->groupBy('d.producto_codigo', 'd.producto_nombre', 'd.categoria_key');

        if ($cat) $q->where('d.categoria_key', $cat);

        return $q->get()->keyBy('cod')->map(fn($r) => [
            'nombre'    => $r->nom,
            'categoria' => $r->cat,
            'avg'       => (float) $r->qty_total / $dias,
        ])->toArray();
    }

    /**
     * Calcula el factor de eventos (Fe) y devuelve [float $Fe, array $etiquetas].
     * Fe base = 1.0; se suman ajustes por feriados, quincena y vacaciones escolares.
     */
    private function vpFactorEventos(string $desde, string $hasta): array
    {
        // Feriados El Salvador (mes-día)
        $feriadosMD = [
            '01-01', '05-01', '05-10', '08-06', '08-07',
            '09-15', '11-02', '12-25', '12-31',
        ];

        $feriadosEnPeriodo = 0;
        $esQuincena        = false;
        $etiquetas         = [];

        $cursor = strtotime($desde);
        $fin    = strtotime($hasta);

        while ($cursor <= $fin) {
            $md  = date('m-d', $cursor);
            $dia = (int) date('d', $cursor);
            $ult = (int) date('t', $cursor); // último día del mes

            if (in_array($md, $feriadosMD)) $feriadosEnPeriodo++;
            if ($dia === 15 || $dia === $ult) $esQuincena = true;

            $cursor += 86400;
        }

        // Vacaciones escolares (marzo–abril y julio–agosto)
        $mDesde = (int) date('m', strtotime($desde));
        $mHasta = (int) date('m', strtotime($hasta));
        $esVacaciones = false;
        for ($m = $mDesde; $m <= $mHasta; $m++) {
            if (in_array($m, [3, 4, 7, 8])) { $esVacaciones = true; break; }
        }

        $Fe = 1.0;
        if ($feriadosEnPeriodo >= 1) {
            $Fe += 0.05 * $feriadosEnPeriodo;
            $etiquetas[] = "feriados({$feriadosEnPeriodo})";
        }
        if ($esQuincena)  { $Fe += 0.08; $etiquetas[] = 'quincena'; }
        if ($esVacaciones){ $Fe += 0.05; $etiquetas[] = 'vacaciones_escolares'; }

        return [min($Fe, 1.40), $etiquetas];
    }

    /**
     * Busca la clave de columna (A, B, C...) en la fila de cabecera
     * que coincida con alguno de los nombres posibles.
     */
    private function findCol(array $header, array $posibleNombres): ?string
    {
        foreach ($header as $key => $value) {
            foreach ($posibleNombres as $nombre) {
                if (str_contains(strtolower($value), $nombre)) {
                    return $key;
                }
            }
        }
        return null;
    }
}
