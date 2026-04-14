<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Estacion extends Model
{
    protected $connection = 'compras';
    protected $table      = 'estaciones';

    protected $fillable = [
        'codigo', 'nombre', 'activa', 'codigo_origen', 'sucursal_id',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];
}
