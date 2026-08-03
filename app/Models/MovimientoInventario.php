<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovimientoInventario extends Model
{
    protected $connection = 'compras';
    protected $table = 'movimientos_inventario';

    protected $fillable = [
        'sucursal_id',
        'producto_id',
        'tipo',
        'cantidad',
        'unidad',
        'cantidad_base',
        'motivo',
        'fecha',
        'referencia_tipo',
        'referencia_id',
        'detalle',
        'aud_usuario',
    ];

    protected $casts = [
        'cantidad'      => 'float',
        'cantidad_base' => 'float',
        'fecha'         => 'date',
        'detalle'       => 'array',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
