<?php

namespace App\Models\Ventas;

use Illuminate\Database\Eloquent\Model;

class VentaOrden extends Model
{
    protected $connection = 'compras';
    protected $table = 'ventas_ordenes';

    protected $fillable = [
        'cliente_id', 'tipo_venta', 'tipo_documento', 'tipo_orden', 'sub_ceco', 'plazo_solicitado', 'estado',
        'subtotal', 'total_iva', 'total', 'notas', 'creado_por',
        'aprobado_por', 'aprobado_at',
        'fecha_facturacion', 'fecha_entrega',
        'despachada_at', 'despachada_por',
        'facturado', 'facturado_por', 'facturado_at',
    ];

    protected $casts = [
        'subtotal'          => 'float',
        'total_iva'         => 'float',
        'total'             => 'float',
        'aprobado_at'       => 'datetime',
        'fecha_facturacion' => 'date',
        'fecha_entrega'     => 'date',
        'despachada_at'     => 'datetime',
        'facturado'         => 'boolean',
        'facturado_at'      => 'datetime',
    ];

    public function cliente()
    {
        return $this->belongsTo(VentaCliente::class, 'cliente_id');
    }

    public function items()
    {
        return $this->hasMany(VentaOrdenItem::class, 'orden_id');
    }

    public function aprobacion()
    {
        return $this->hasOne(VentaAprobacion::class, 'orden_id');
    }
}
