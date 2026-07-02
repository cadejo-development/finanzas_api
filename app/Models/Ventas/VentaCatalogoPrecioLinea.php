<?php

namespace App\Models\Ventas;

use Illuminate\Database\Eloquent\Model;

class VentaCatalogoPrecioLinea extends Model
{
    protected $connection = 'compras';
    protected $table = 'ventas_catalogo_precio_lineas';

    protected $fillable = ['catalogo_id', 'producto_id', 'precio_sin_iva', 'precio_con_iva', 'cantidad_minima'];

    protected $casts = [
        'precio_sin_iva'  => 'float',
        'precio_con_iva'  => 'float',
        'cantidad_minima' => 'integer',
    ];

    public function catalogo()
    {
        return $this->belongsTo(VentaCatalogoPrecio::class, 'catalogo_id');
    }

    public function producto()
    {
        return $this->belongsTo(\App\Models\Producto::class, 'producto_id');
    }
}
