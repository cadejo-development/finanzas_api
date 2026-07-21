<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PropinaDetalle extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'propina_detalles';

    protected $fillable = [
        'periodo_id', 'empleado_id',
        'puntos_propina', 'fuente_puntos',
        'dias_quincena', 'dias_no_laborados', 'dias_laborados',
        'override_dias', 'detalle_ausencias',
        'propina_diaria', 'propina_quincena',
        'sobrante_aplicado', 'propina_adicional', 'total_propina',
        'incluido', 'notas',
    ];

    protected $casts = [
        'puntos_propina'    => 'decimal:2',
        'dias_no_laborados' => 'decimal:2',
        'dias_laborados'    => 'decimal:2',
        'override_dias'     => 'boolean',
        'detalle_ausencias' => 'array',
        'propina_diaria'    => 'decimal:4',
        'propina_quincena'  => 'decimal:2',
        'sobrante_aplicado' => 'decimal:2',
        'propina_adicional' => 'decimal:2',
        'total_propina'     => 'decimal:2',
        'incluido'          => 'boolean',
    ];

    public function periodo()
    {
        return $this->belongsTo(PropinaPeriodo::class, 'periodo_id');
    }
}
