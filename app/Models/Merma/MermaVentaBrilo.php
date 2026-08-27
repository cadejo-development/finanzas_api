<?php

namespace App\Models\Merma;

use Illuminate\Database\Eloquent\Model;

class MermaVentaBrilo extends Model
{
    protected $connection = 'compras';
    protected $table      = 'merma_ventas_brilo';
    public    $timestamps = false;

    protected $fillable = [
        'inventario_id', 'cerveza_id', 'presentacion_id',
        'presentacion_brilo', 'cerveza_brilo',
        'cantidad', 'oz_efectivas', 'fecha', 'suc_id_brilo', 'synced_at',
    ];

    protected $casts = [
        'fecha'        => 'date',
        'oz_efectivas' => 'decimal:2',
        'synced_at'    => 'datetime',
    ];
}
