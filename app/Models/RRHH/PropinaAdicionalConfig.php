<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PropinaAdicionalConfig extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'propina_adicional_config';

    protected $fillable = [
        'sucursal_id', 'cargo_id', 'monto', 'descripcion', 'activa',
    ];

    protected $casts = [
        'monto'  => 'decimal:2',
        'activa' => 'boolean',
    ];
}
