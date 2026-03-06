<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PedidoDetalle extends Model
{
    protected $connection = 'compras';
    protected $table = 'pedido_detalle';
    protected $fillable = [
        'pedido_id', 'producto_id', 'cantidad', 'unidad', 'nota', 'precio_unitario', 'subtotal', 'aud_usuario'
    ];
    protected $casts = [
        'cantidad' => 'decimal:2',
        'precio_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];
    public function pedido()
    {
        return $this->belongsTo(Pedido::class);
    }
    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
