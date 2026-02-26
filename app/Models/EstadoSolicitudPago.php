<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstadoSolicitudPago extends Model
{
    protected $connection = 'pagos';
    protected $table = 'estados_solicitud_pago';
    protected $fillable = [
        'codigo', 'nombre', 'descripcion'
    ];
}
