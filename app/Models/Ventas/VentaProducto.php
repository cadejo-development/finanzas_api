<?php

namespace App\Models\Ventas;

use Illuminate\Database\Eloquent\Model;

class VentaProducto extends Model
{
    protected $connection = 'compras';
    protected $table = 'ventas_productos';

    protected $fillable = [
        'brilo_id', 'codigo', 'nombre', 'exento', 'precio',
        'existencias', 'bodega', 'bodega_id', 'activo',
    ];

    protected $casts = [
        'exento' => 'boolean',
        'activo' => 'boolean',
        'precio' => 'float',
        'existencias' => 'float',
    ];
}
