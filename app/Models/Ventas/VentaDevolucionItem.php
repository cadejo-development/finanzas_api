<?php

namespace App\Models\Ventas;

use Illuminate\Database\Eloquent\Model;

class VentaDevolucionItem extends Model
{
    protected $connection = 'compras';
    protected $table = 'ventas_devolucion_items';

    public $timestamps = false;

    protected $fillable = [
        'devolucion_id', 'producto_id', 'nombre_producto', 'cantidad',
        'precio_unitario', 'exento', 'subtotal', 'iva', 'total', 'motivo_item',
    ];

    protected $casts = [
        'exento'          => 'boolean',
        'cantidad'        => 'float',
        'precio_unitario' => 'float',
        'subtotal'        => 'float',
        'iva'             => 'float',
        'total'           => 'float',
    ];

    public function producto()
    {
        return $this->belongsTo(VentaProducto::class, 'producto_id');
    }
}
