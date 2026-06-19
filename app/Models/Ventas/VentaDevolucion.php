<?php

namespace App\Models\Ventas;

use Illuminate\Database\Eloquent\Model;

class VentaDevolucion extends Model
{
    protected $connection = 'compras';
    protected $table = 'ventas_devoluciones';

    protected $fillable = [
        'orden_id', 'cliente_id', 'tipo', 'estado', 'motivo', 'notas',
        'subtotal', 'total_iva', 'total', 'creado_por', 'aprobado_por', 'aprobado_at',
    ];

    protected $casts = [
        'subtotal'    => 'float',
        'total_iva'   => 'float',
        'total'       => 'float',
        'aprobado_at' => 'datetime',
    ];

    public function orden()
    {
        return $this->belongsTo(VentaOrden::class, 'orden_id');
    }

    public function cliente()
    {
        return $this->belongsTo(VentaCliente::class, 'cliente_id');
    }

    public function items()
    {
        return $this->hasMany(VentaDevolucionItem::class, 'devolucion_id');
    }
}
