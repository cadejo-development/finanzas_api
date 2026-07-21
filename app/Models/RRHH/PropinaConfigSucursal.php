<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PropinaConfigSucursal extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'propina_config_sucursal';

    protected $fillable = [
        'sucursal_id', 'nombre_grupo',
        'pct_propina_sobre_venta', 'pct_distribucion_empleados',
        'dias_quincena_1', 'dias_quincena_2',
        'activa', 'notas',
    ];

    protected $casts = [
        'pct_propina_sobre_venta'     => 'decimal:4',
        'pct_distribucion_empleados'  => 'decimal:4',
        'activa'                      => 'boolean',
    ];
}
