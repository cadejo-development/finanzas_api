<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormaPago extends Model
{
    protected $connection = 'pagos';
    protected $table = 'formas_pago';
    protected $fillable = [
        'codigo', 'nombre', 'activo', 'aud_usuario'
    ];
    protected $casts = [
        'activo' => 'boolean',
    ];
    public function solicitudesPago() { return $this->hasMany(SolicitudPago::class); }
}
