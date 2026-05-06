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

                // Modificadores (igual que RecetasController::formatReceta)
                $costoModificadores = (float) $receta->modificadores->sum(function ($mod) {
                    if (!$mod->producto) return 0.0;
                    $costoUnit = $this->costoPorUnidadReceta($mod->producto, strtolower(trim($mod->unidad ?? '')));
                    return $costoUnit * (float) ($mod->cantidad ?? 0);
                });

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

        $convCase = "CASE
            WHEN LOWER(l.unidad_receta) = LOWER(p.unidad) THEN 1
            WHEN p.factor_conversion IS NOT NULL AND p.unidad_base IS NOT NULL
             AND LOWER(l.unidad_receta) = LOWER(p.unidad_base) THEN 1.0/p.factor_conversion
            WHEN LOWER(l.unidad_receta)='oz'  AND LOWER(p.unidad)='lb'               THEN 1.0/16
            WHEN LOWER(l.unidad_receta)='lb'  AND LOWER(p.unidad)='oz'               THEN 16
            WHEN LOWER(l.unidad_receta)='g'   AND LOWER(p.unidad)='kg'               THEN 1.0/1000
            WHEN LOWER(l.unidad_receta)='kg'  AND LOWER(p.unidad)='g'                THEN 1000
            WHEN LOWER(l.unidad_receta)='g'   AND LOWER(p.unidad)='lb'               THEN 1.0/453.592
            WHEN LOWER(l.unidad_receta)='lb'  AND LOWER(p.unidad)='g'                THEN 453.592
            WHEN LOWER(l.unidad_receta)='ml'  AND LOWER(p.unidad) IN ('l','lt','lts') THEN 1.0/1000
            WHEN LOWER(l.unidad_receta) IN ('l','lt','lts') AND LOWER(p.unidad)='ml' THEN 1000
            ELSE 1 END";

        $rows = DB::connection('compras')->select("
            SELECT
                p.nombre             AS ingrediente,
                p.codigo             AS ingrediente_codigo,
                l.unidad_receta,
                p.unidad             AS unidad_compra,
                p.unidad_base,
                p.factor_conversion,
                COALESCE(p.costo,0)  AS costo_unitario,
                ROUND(SUM(l.cantidad_usada)::numeric, 3) AS total_consumido,
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
            GROUP BY p.id, p.nombre, p.codigo, l.unidad_receta, p.unidad, p.unidad_base, p.factor_conversion, p.costo
            ORDER BY costo_total DESC
        ", $bindings);

        // Tabla de conversiones estándar: 'unidad_receta:unidad_compra' => factor multiplicador
        $conversiones = [
            'oz:lb'  => 1 / 16,        'lb:oz'  => 16,
            'oz:kg'  => 1 / 35.274,    'kg:oz'  => 35.274,
            'g:kg'   => 1 / 1000,      'kg:g'   => 1000,
            'g:lb'   => 1 / 453.592,   'lb:g'   => 453.592,
            'ml:l'   => 1 / 1000,      'l:ml'   => 1000,
            'ml:lt'  => 1 / 1000,      'lt:ml'  => 1000,
            'ml:lts' => 1 / 1000,      'lts:ml' => 1000,
        ];

        $ingredientes = array_map(function ($r) use ($conversiones) {
            $totalConsumido = (float) $r->total_consumido;
            $uRec  = strtolower(trim($r->unidad_receta  ?? ''));
            $uComp = strtolower(trim($r->unidad_compra  ?? ''));
            $uBase = strtolower(trim($r->unidad_base    ?? ''));
            $factor = $r->factor_conversion ? (float) $r->factor_conversion : null;

            $totalEnCompra    = null;
            $unidadesDifieren = $uRec !== $uComp;

            if ($unidadesDifieren) {
                // Prioridad 1: factor_conversion del producto (unidad_base → unidad_compra)
                if ($factor && $uBase && $uRec === $uBase) {
                    $totalEnCompra = round($totalConsumido / $factor, 4);
                } else {
                    // Prioridad 2: conversión estándar
                    $key = "{$uRec}:{$uComp}";
                    if (isset($conversiones[$key])) {
                        $totalEnCompra = round($totalConsumido * $conversiones[$key], 4);
                    }
                }
            }

            return [
                'ingrediente'         => $r->ingrediente,
                'codigo'              => $r->ingrediente_codigo,
                'unidad_receta'       => $r->unidad_receta,
                'unidad_compra'       => $r->unidad_compra,
                'unidades_difieren'   => $unidadesDifieren,
                'total_consumido'     => $totalConsumido,
                'total_en_compra'     => $totalEnCompra,
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

    // ─── Helpers ─────────────────────────────────────────────────────────────

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
