<?php

namespace App\Models\Ventas;

use Illuminate\Database\Eloquent\Model;

class VentaPromocion extends Model
{
    protected $connection = 'compras';
    protected $table = 'ventas_promociones';

    protected $fillable = [
        'nombre', 'descripcion', 'tipo', 'cantidad_minima', 'cantidad_bonificada',
        'aplica_mix_sku', 'bonifica_menor_precio', 'canal', 'notas', 'activo',
    ];

    protected $casts = [
        'aplica_mix_sku'        => 'boolean',
        'bonifica_menor_precio' => 'boolean',
        'activo'                => 'boolean',
        'cantidad_minima'       => 'integer',
        'cantidad_bonificada'   => 'integer',
    ];
}
