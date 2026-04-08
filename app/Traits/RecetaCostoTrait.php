<?php

namespace App\Traits;

use App\Models\Receta;

/**
 * Lógica de cálculo de costos de recetas y sub-recetas.
 * Reutilizable en RecetasController y ProductosController.
 */
trait RecetaCostoTrait
{
    /**
     * Calcula el costo por unidad de una sub-receta.
     * Si el producto asociado ya tiene costo > 0 almacenado lo usa directamente.
     * Caso contrario lo calcula recursivamente desde sus ingredientes.
     *
     * @param  Receta|null  $sub          Sub-receta a calcular
     * @param  string|null  $unidadReceta Unidad que usa la receta padre (ej: 'oz', 'kg')
     * @param  int          $depth        Profundidad de recursión (límite 5)
     */
    protected function calcularCostoSubReceta(?Receta $sub, ?string $unidadReceta = null, int $depth = 0): float
    {
        if (!$sub || $depth > 5) return 0.0;

        // Si el producto asociado tiene costo pre-almacenado, usarlo directamente (fast-path).
        $prod = $sub->productoAsociado ?? null;
        if (!$prod && $sub->codigo_origen) {
            $prod = \App\Models\Producto::where('codigo', $sub->codigo_origen)->first();
        }
        if ($prod && (float) $prod->costo > 0) {
            return $this->costoPorUnidadReceta($prod, strtolower(trim($unidadReceta ?? '')));
        }

        // Cargar ingredientes si no están cargados.
        if (!$sub->relationLoaded('ingredientes')) {
            $sub->load([
                'ingredientes.producto',
                'ingredientes.subReceta.productoAsociado',
                'ingredientes.subReceta.ingredientes.producto',
            ]);
        }

        $batchCosto = (float) $sub->ingredientes->sum(function ($si) use ($depth) {
            if ($si->sub_receta_id && $si->subReceta) {
                return (float) $si->cantidad_por_plato
                    * $this->calcularCostoSubReceta($si->subReceta, $si->unidad, $depth + 1);
            }
            $costoUnit = $si->producto
                ? $this->costoPorUnidadReceta($si->producto, strtolower(trim($si->unidad ?? '')))
                : 0.0;
            return (float) $si->cantidad_por_plato * $costoUnit;
        });

        $rendimiento = (float) ($sub->rendimiento ?? 0);
        $rendUnidad  = strtolower(trim($sub->rendimiento_unidad ?? ''));
        if ($rendimiento > 0 && $rendUnidad) {
            $costePorUnidad = $batchCosto / $rendimiento;
            $targetUnit     = strtolower(trim($unidadReceta ?? ''));
            if (!$targetUnit || $targetUnit === $rendUnidad) {
                return $costePorUnidad;
            }
            return $this->convertirCosto($costePorUnidad, $rendUnidad, $targetUnit);
        }

        $subUnit = strtolower(trim($prod?->unidad ?? ''));
        if ($subUnit && $unidadReceta) {
            $knownUnits  = ['lb', 'oz', 'g', 'kg', 'lt', 'ml', 'oz fl', 'galon'];
            $effectiveUnit = in_array($subUnit, $knownUnits, true) ? $subUnit : 'lb';
            $batchCosto  = $this->convertirCosto($batchCosto, $effectiveUnit, strtolower(trim($unidadReceta)));
        }

        return $batchCosto;
    }

    /**
     * Calcula el costo del batch completo de una sub-receta desde sus ingredientes,
     * SIN usar el fast-path de productos.costo. Útil para sincronizar el costo al DB.
     */
    protected function calcularBatchCostoDirecto(Receta $receta): float
    {
        if (!$receta->relationLoaded('ingredientes')) {
            $receta->load([
                'ingredientes.producto',
                'ingredientes.subReceta.productoAsociado',
                'ingredientes.subReceta.ingredientes.producto',
            ]);
        }

        return (float) $receta->ingredientes->sum(function ($ing) {
            if ($ing->sub_receta_id && $ing->subReceta) {
                return (float) $ing->cantidad_por_plato
                    * $this->calcularCostoSubReceta($ing->subReceta, $ing->unidad, 1);
            }
            $costoUnit = $ing->producto
                ? $this->costoPorUnidadReceta($ing->producto, strtolower(trim($ing->unidad ?? '')))
                : 0.0;
            return (float) $ing->cantidad_por_plato * $costoUnit;
        });
    }

    /**
     * Sincroniza productos.costo con el costo calculado de la sub-receta.
     * Llamar después de guardar/actualizar una sub-receta.
     */
    protected function sincronizarCostoProducto(Receta $receta): void
    {
        if ($receta->tipo_receta !== 'sub_receta' || !$receta->codigo_origen) {
            return;
        }

        $prod = \App\Models\Producto::where('codigo', $receta->codigo_origen)->first();
        if (!$prod) return;

        $batchCosto  = $this->calcularBatchCostoDirecto($receta);
        $rendimiento = (float) ($receta->rendimiento ?? 0);
        $costoUnitario = $rendimiento > 0 ? $batchCosto / $rendimiento : $batchCosto;

        if ($costoUnitario > 0) {
            $prod->update([
                'costo'       => round($costoUnitario, 4),
                'aud_usuario' => 'sistema-sync',
            ]);
        }
    }

    /**
     * Convierte el costo por unidad de compra del producto a la unidad usada en la receta.
     */
    protected function costoPorUnidadReceta(\App\Models\Producto $prod, string $haciaUnidad): float
    {
        $costo = (float) $prod->costo;
        if ($costo === 0.0) return 0.0;

        $factor   = $prod->factor_conversion ? (float) $prod->factor_conversion : null;
        $unidBase = $factor ? strtolower(trim($prod->unidad_base ?? '')) : null;

        if ($factor && $factor > 0 && $unidBase) {
            $costoPorBase = $costo / $factor;
            if (!$haciaUnidad || $haciaUnidad === $unidBase) return $costoPorBase;
            return $this->convertirCosto($costoPorBase, $unidBase, $haciaUnidad);
        }

        $prodUnit = strtolower(trim($prod->unidad ?? ''));
        if (!$haciaUnidad || $haciaUnidad === $prodUnit) return $costo;
        return $this->convertirCosto($costo, $prodUnit, $haciaUnidad);
    }

    /**
     * Convierte el costo de una unidad a otra usando la tabla de conversión.
     */
    protected function convertirCosto(float $costo, string $desdePorUnidad, string $haciaUnidad): float
    {
        if ($costo === 0.0 || $desdePorUnidad === $haciaUnidad) return $costo;

        $conv = [
            'lb'    => ['oz' => 1/16,       'g'    => 1/453.592,  'kg'    => 1/0.453592, 'lb'    => 1],
            'kg'    => ['g'  => 1/1000,     'oz'   => 1/35.274,   'lb'    => 1/2.20462,  'kg'    => 1],
            'oz'    => ['lb' => 16,         'g'    => 28.3495,    'oz'    => 1],
            'g'     => ['lb' => 453.592,    'kg'   => 1000,       'oz'    => 28.3495,     'g'     => 1],
            'lt'    => ['ml' => 1/1000,     'oz fl'=> 1/33.814,   'galon' => 3.78541,    'lt'    => 1],
            'galon' => ['oz fl' => 1/128,   'lt'   => 1/3.78541,  'ml'    => 1/3785.41,  'galon' => 1],
            'oz fl' => ['galon' => 128,     'lt'   => 33.814,     'ml'    => 33.814/1000, 'oz fl' => 1],
            'ml'    => ['lt' => 1000,       'oz fl'=> 1000/33.814,'galon' => 3785.41,    'ml'    => 1],
        ];

        $factor = $conv[$desdePorUnidad][$haciaUnidad] ?? null;
        return $factor !== null ? $costo * $factor : $costo;
    }
}
