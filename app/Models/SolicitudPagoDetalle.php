<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudPagoDetalle extends Model
{
    protected $connection = 'pagos';
    protected $table = 'solicitud_pago_detalles';
    protected $fillable = [
        'solicitud_pago_id', 'concepto', 'centro_costo_id', 'cantidad', 'precio_unitario', 'subtotal_linea', 'aud_usuario'
    ];
    protected $casts = [
        'cantidad' => 'decimal:2',
        'precio_unitario' => 'decimal:2',
        'subtotal_linea' => 'decimal:2',
    ];
    public function solicitud() { return $this->belongsTo(SolicitudPago::class, 'solicitud_pago_id'); }
    public function centroCosto() { return $this->belongsTo(CentroCosto::class, 'centro_costo_id'); }
}
