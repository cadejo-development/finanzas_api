<?php

namespace App\Models\Ventas;

use Illuminate\Database\Eloquent\Model;

class VentaAprobacion extends Model
{
    protected $connection = 'compras';
    protected $table = 'ventas_aprobaciones';

    protected $fillable = [
        'tipo', 'orden_id', 'cliente_id', 'detalle', 'estado',
        'solicitado_por', 'resuelto_por', 'nota_resolucion', 'resuelto_at',
    ];

    protected $casts = [
        'resuelto_at' => 'datetime',
    ];

    public function orden()
    {
        return $this->belongsTo(VentaOrden::class, 'orden_id');
    }

    public function cliente()
    {
        return $this->belongsTo(VentaCliente::class, 'cliente_id');
    }
}
