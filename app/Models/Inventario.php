<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventario extends Model
{
    protected $connection = 'compras';
    protected $table = 'inventarios';

    protected $fillable = [
        'sucursal_id',
        'producto_id',
        'cantidad_inicial',
        'unidad',
        'cantidad_inicial_base',
        'fecha_conteo',
        'stock_minimo',
        'seccion',
        'brilo_stock',
        'brilo_sync_at',
        'aud_usuario',
    ];

    protected $casts = [
        'cantidad_inicial'      => 'float',
        'cantidad_inicial_base' => 'float',
        'stock_minimo'          => 'float',
        'brilo_stock'           => 'float',
        'fecha_conteo'          => 'date',
        'brilo_sync_at'         => 'datetime',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function movimientos()
    {
        return $this->hasMany(MovimientoInventario::class, 'producto_id', 'producto_id')
            ->where('sucursal_id', $this->sucursal_id);
    }
}
