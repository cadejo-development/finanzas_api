<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PlanillaTablaRenta extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'planilla_tabla_renta';

    protected $fillable = [
        'desde', 'hasta', 'cuota_fija', 'porcentaje',
        'sobre_exceso_de', 'vigente_desde', 'activo',
    ];

    protected $casts = [
        'desde'           => 'decimal:2',
        'hasta'           => 'decimal:2',
        'cuota_fija'      => 'decimal:2',
        'porcentaje'      => 'decimal:2',
        'sobre_exceso_de' => 'decimal:2',
        'vigente_desde'   => 'date',
        'activo'          => 'boolean',
    ];
}
