<?php

namespace App\Models\Ventas;

use Illuminate\Database\Eloquent\Model;

class VentaPago extends Model
{
    protected $connection = 'compras';
    protected $table = 'ventas_pagos';

    protected $fillable = [
        'orden_id', 'fecha', 'forma_pago', 'monto', 'comprobante', 'registrado_por', 'notas',
    ];

    protected $casts = [
        'monto' => 'float',
        'fecha' => 'date',
    ];

    public function orden()
    {
        return $this->belongsTo(VentaOrden::class, 'orden_id');
    }
}
