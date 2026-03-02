<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudPago extends Model
{
    protected $connection = 'pagos';
    protected $table = 'solicitudes_pago';
    protected $fillable = [
        'codigo', 'fecha_solicitud', 'fecha_pago', 'forma_pago_id', 'proveedor_id', 'contribuyente_id', 'personeria', 'es_servicio', 'tipo_gasto', 'estado_id', 'nivel_aprobacion', 'aprobador_asignado', 'sub_total', 'iva', 'ret_isr', 'perc_iva_1', 'a_pagar', 'aud_usuario', 'solicitante_id', 'solicitante_nombre',
    ];
    protected $casts = [
        'fecha_solicitud' => 'date',
        'fecha_pago' => 'date',
        'es_servicio' => 'boolean',
        'sub_total' => 'decimal:2',
        'iva' => 'decimal:2',
        'ret_isr' => 'decimal:2',
        'perc_iva_1' => 'decimal:2',
        'a_pagar' => 'decimal:2',
    ];
    public function detalles() { return $this->hasMany(SolicitudPagoDetalle::class); }
    public function adjuntos() { return $this->hasMany(SolicitudPagoAdjunto::class); }
    public function proveedor() { return $this->belongsTo(Proveedor::class); }
    public function contribuyente() { return $this->belongsTo(Contribuyente::class); }
    public function formaPago() { return $this->belongsTo(FormaPago::class); }
    public function estadoSolicitudPago() { return $this->belongsTo(EstadoSolicitudPago::class, 'estado_id'); }
    public function aprobaciones() { return $this->hasMany(SolicitudPagoAprobacion::class); }
}
