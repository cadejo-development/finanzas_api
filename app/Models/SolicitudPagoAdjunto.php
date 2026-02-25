<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudPagoAdjunto extends Model
{
    protected $connection = 'pagos';
    protected $table = 'solicitud_pago_adjuntos';
    protected $fillable = [
        'solicitud_pago_id', 'nombre_archivo', 'url', 'tipo', 'aud_usuario'
    ];
    public function solicitud() { return $this->belongsTo(SolicitudPago::class, 'solicitud_pago_id'); }
}
