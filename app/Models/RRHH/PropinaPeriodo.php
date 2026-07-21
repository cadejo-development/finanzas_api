<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PropinaPeriodo extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'propina_periodos';

    protected $fillable = [
        'sucursal_id', 'anio', 'mes', 'quincena',
        'fecha_inicio', 'fecha_fin', 'dias_quincena',
        'venta_quincena', 'propina_total_recolectada',
        'propina_tabla', 'puntos_totales', 'valor_punto_propina',
        'propina_repartida', 'retencion_monto', 'retencion_pct',
        'excedente_vs_tabla', 'sobrante_generado', 'sobrante_aplicado_monto',
        'estado', 'planilla_id', 'elaborado_por', 'aprobado_por', 'aprobado_en', 'notas',
    ];

    protected $casts = [
        'fecha_inicio'            => 'date',
        'fecha_fin'               => 'date',
        'aprobado_en'             => 'datetime',
        'venta_quincena'          => 'decimal:2',
        'propina_total_recolectada' => 'decimal:2',
        'propina_tabla'           => 'decimal:2',
        'puntos_totales'          => 'decimal:2',
        'valor_punto_propina'     => 'decimal:4',
        'propina_repartida'       => 'decimal:2',
        'retencion_monto'         => 'decimal:2',
        'retencion_pct'           => 'decimal:4',
        'excedente_vs_tabla'      => 'decimal:2',
        'sobrante_generado'       => 'decimal:2',
        'sobrante_aplicado_monto' => 'decimal:2',
    ];

    public function detalles()
    {
        return $this->hasMany(PropinaDetalle::class, 'periodo_id');
    }

    public function sobrantes()
    {
        return $this->hasMany(PropinaSobrante::class, 'periodo_origen_id');
    }
}
