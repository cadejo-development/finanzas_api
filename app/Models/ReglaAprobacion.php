<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReglaAprobacion extends Model
{
    protected $connection = 'pagos';
    protected $table      = 'reglas_aprobacion';

    protected $fillable = [
        'tipo_gasto',
        'nivel_orden',
        'nivel_codigo',
        'rol_requerido',
        'etiqueta',
        'monto_min',
        'monto_max',
        'activo',
    ];

    protected $casts = [
        'monto_min' => 'float',
        'monto_max' => 'float',
        'activo'    => 'boolean',
    ];

    /**
     * Devuelve todas las reglas activas para un tipo de gasto
     * filtradas por el monto dado, ordenadas por nivel_orden.
     */
    public static function paraGasto(string $tipoGasto, float $monto): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('tipo_gasto', strtoupper($tipoGasto))
            ->where('activo', true)
            ->orderBy('nivel_orden')
            ->orderBy('id')
            ->get()
            ->filter(fn(self $r) => $r->aplicaParaMonto($monto))
            ->values();
    }

    /**
     * Determina si esta regla aplica para el monto dado.
     * - monto_min NULL y monto_max NULL → siempre aplica (visto bueno incondicional)
     * - Solo monto_max NULL → aplica si monto >= monto_min
     * - Ambos presentes → aplica si monto_min <= monto <= monto_max
     */
    public function aplicaParaMonto(float $monto): bool
    {
        $min = $this->monto_min;
        $max = $this->monto_max;

        // Incondicional (visto bueno paralelo)
        if ($min === null && $max === null) {
            return true;
        }

        $cumpleMin = $min === null || $monto >= $min;
        $cumpleMax = $max === null || $monto <= $max;

        return $cumpleMin && $cumpleMax;
    }

    /**
     * ¿Es un paso de visto bueno?
     */
    public function esVistoBueno(): bool
    {
        return $this->nivel_codigo === 'visto_bueno';
    }

    /**
     * Rango formateado para mostrar en el frontend.
     */
    public function getRangoAttribute(): string
    {
        if ($this->monto_min === null && $this->monto_max === null) {
            return 'Siempre';
        }
        if ($this->monto_max === null) {
            return '$' . number_format($this->monto_min, 2) . ' en adelante';
        }
        $min = $this->monto_min ?? 0;
        return '$' . number_format($min, 2) . ' – $' . number_format($this->monto_max, 2);
    }
}
