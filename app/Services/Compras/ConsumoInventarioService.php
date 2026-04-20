<?php

namespace App\Services\Compras;

use Illuminate\Support\Facades\DB;

/**
 * Calcula el consumo de materias primas a partir de ventas semanales.
 *
 * Flujo:
 *   ventas_semanales_detalle.producto_codigo
 *     → recetas.codigo_origen  (mapeo plato vendido → receta)
 *     → receta_ingredientes    (ingredientes y cantidades por plato)
 *     → productos              (unidad_base + factor_conversion)
 *   Resultado: consumo en unidad_base por producto_id
 */
class ConsumoInventarioService
{
    /**
     * Retorna el consumo de materias primas para una sucursal en un rango de fechas.
     *
     * @param  int    $sucursalId
     * @param  string $fechaDesde  YYYY-MM-DD
     * @param  string $fechaHasta  YYYY-MM-DD
     * @return array  [ producto_id => ['cantidad_base' => float, 'unidad_base' => string, 'producto_nombre' => string, 'detalle' => [...]] ]
     */
    public function calcular(int $sucursalId, string $fechaDesde, string $fechaHasta): array
    {
        // ── 1. Ventas del período para esta sucursal ──────────────────────────
        $ventas = DB::connection('compras')
            ->table('ventas_semanales_detalle as vd')
            ->join('ventas_semanales as vs', 'vs.id', '=', 'vd.venta_semanal_id')
            ->where('vs.sucursal_id', $sucursalId)
            ->whereBetween('vs.semana_inicio', [$fechaDesde, $fechaHasta])
            ->select('vd.producto_codigo', 'vd.producto_nombre', 'vd.cantidad_vendida')
            ->get();

        if ($ventas->isEmpty()) {
            return [];
        }

        // ── 2. Agrupar cantidades vendidas por código de producto ─────────────
        $ventasPorCodigo = [];
        foreach ($ventas as $v) {
            $cod = strtoupper(trim($v->producto_codigo));
            $ventasPorCodigo[$cod] = ($ventasPorCodigo[$cod] ?? 0) + (float) $v->cantidad_vendida;
        }

        $codigos = array_keys($ventasPorCodigo);

        // ── 3. Mapear código → receta ─────────────────────────────────────────
        $recetas = DB::connection('compras')
            ->table('recetas')
            ->whereIn(DB::raw('UPPER(TRIM(codigo_origen))'), $codigos)
            ->where('activa', true)
            ->select('id', 'codigo_origen', 'nombre', 'rendimiento')
            ->get()
            ->keyBy(fn($r) => strtoupper(trim($r->codigo_origen)));

        if ($recetas->isEmpty()) {
            return [];
        }

        $recetaIds = $recetas->pluck('id')->all();

        // ── 4. Ingredientes de esas recetas ───────────────────────────────────
        $ingredientes = DB::connection('compras')
            ->table('receta_ingredientes as ri')
            ->join('productos as p', 'p.id', '=', 'ri.producto_id')
            ->whereIn('ri.receta_id', $recetaIds)
            ->whereNull('ri.sub_receta_id')
            ->select(
                'ri.receta_id',
                'ri.producto_id',
                'ri.cantidad_por_plato',
                'ri.unidad as unidad_receta',
                'p.nombre as producto_nombre',
                'p.unidad_base',
                'p.factor_conversion',
                'p.unidad',
            )
            ->get()
            ->groupBy('receta_id');

        // ── 5. Calcular consumo acumulado por producto ────────────────────────
        $consumo = [];  // producto_id → acumulado en unidad_base

        foreach ($recetas as $codigoUpper => $receta) {
            $cantidadVendida = $ventasPorCodigo[$codigoUpper] ?? 0;
            if ($cantidadVendida <= 0) continue;

            $ings = $ingredientes->get($receta->id, collect());

            // Factor de rendimiento: si la receta rinde N porciones, dividir ingrediente por N
            $rendimiento = max((float) ($receta->rendimiento ?? 1), 1);

            foreach ($ings as $ing) {
                $cantidadPorPlato = (float) $ing->cantidad_por_plato;

                // Convertir a unidad_base usando el factor del producto
                $cantidadBase = $this->aUnidadBase(
                    $cantidadPorPlato,
                    $ing->unidad_receta,
                    $ing->unidad_base,
                    (float) ($ing->factor_conversion ?? 1),
                );

                $consumoPorPlato = $cantidadBase / $rendimiento;
                $consumoTotal    = $consumoPorPlato * $cantidadVendida;

                $pid = (int) $ing->producto_id;

                if (!isset($consumo[$pid])) {
                    $consumo[$pid] = [
                        'producto_id'     => $pid,
                        'producto_nombre' => $ing->producto_nombre,
                        'unidad_base'     => $ing->unidad_base,
                        'unidad_compra'   => $ing->unidad,
                        'factor_conversion' => (float) ($ing->factor_conversion ?? 1),
                        'cantidad_base'   => 0.0,
                        'detalle'         => [],
                    ];
                }

                $consumo[$pid]['cantidad_base'] += $consumoTotal;
                $consumo[$pid]['detalle'][] = [
                    'receta_nombre'   => $receta->nombre,
                    'cantidad_vendida'=> $cantidadVendida,
                    'consumo_base'    => round($consumoTotal, 6),
                ];
            }
        }

        // Redondear resultados
        foreach ($consumo as &$c) {
            $c['cantidad_base'] = round($c['cantidad_base'], 6);
        }

        return array_values($consumo);
    }

    /**
     * Convierte una cantidad desde la unidad de la receta a la unidad_base del producto.
     *
     * Las unidades de medida comunes y su relación con gramos/mililitros:
     *   g, gr, gramo  → base directa (si unidad_base = g)
     *   kg, kilo      → × 1000
     *   oz, onza      → × 28.3495
     *   lb, libra     → × 453.592
     *   lt, litro     → × 1000  (base = ml)
     *   ml            → base directa
     *   u, unidad     → 1:1
     *
     * Si la unidad_receta coincide con unidad_base → sin conversión.
     * Si no hay regla conocida → usa factor_conversion del producto.
     */
    private function aUnidadBase(
        float  $cantidad,
        string $unidadReceta,
        string $unidadBase,
        float  $factorProducto,
    ): float {
        $ur = strtolower(trim($unidadReceta));
        $ub = strtolower(trim($unidadBase));

        // Misma unidad → sin conversión
        if ($ur === $ub) return $cantidad;

        // Tabla de conversión hacia gramos
        $aGramos = [
            'g' => 1, 'gr' => 1, 'gramo' => 1, 'gramos' => 1,
            'kg' => 1000, 'kilo' => 1000, 'kilogramo' => 1000, 'kilogramos' => 1000,
            'oz' => 28.3495, 'onza' => 28.3495, 'onzas' => 28.3495,
            'lb' => 453.592, 'libra' => 453.592, 'libras' => 453.592,
        ];

        // Tabla de conversión hacia mililitros
        $aMl = [
            'ml' => 1, 'mililitro' => 1,
            'lt' => 1000, 'litro' => 1000, 'litros' => 1000, 'l' => 1000,
            'oz fl' => 29.5735, 'oz_fl' => 29.5735, 'fl oz' => 29.5735,
        ];

        // Si unidad_base es g/gr → convertir a gramos
        if (in_array($ub, ['g', 'gr', 'gramo', 'gramos'])) {
            if (isset($aGramos[$ur])) return $cantidad * $aGramos[$ur];
        }

        // Si unidad_base es ml → convertir a ml
        if (in_array($ub, ['ml', 'mililitro'])) {
            if (isset($aMl[$ur])) return $cantidad * $aMl[$ur];
        }

        // Si unidad_base es kg → receta podría venir en g
        if (in_array($ub, ['kg', 'kilo'])) {
            if (in_array($ur, ['g', 'gr', 'gramo', 'gramos'])) return $cantidad / 1000;
            if (in_array($ur, ['oz', 'onza', 'onzas'])) return $cantidad * 28.3495 / 1000;
            if (in_array($ur, ['lb', 'libra', 'libras'])) return $cantidad * 453.592 / 1000;
        }

        // Fallback: usar factor_conversion del producto
        // Asume que unidad_receta == unidad de compra del producto
        return $cantidad * $factorProducto;
    }
}
