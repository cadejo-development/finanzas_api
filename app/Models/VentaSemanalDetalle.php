<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaSemanalDetalle extends Model
{
    protected $connection = 'compras';
    protected $table = 'ventas_semanales_detalle';

    protected $fillable = [
        'venta_semanal_id',
        'producto_codigo',
        'producto_nombre',
        'categoria_key',
        'cantidad_vendida',
        'precio_unitario',
        'total',
    ];

    protected $casts = [
        'cantidad_vendida' => 'float',
        'precio_unitario'  => 'float',
        'total'            => 'float',
    ];

    public function ventaSemanal(): BelongsTo
    {
        return $this->belongsTo(VentaSemanal::class, 'venta_semanal_id');
    }
}
