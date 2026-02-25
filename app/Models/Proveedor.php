<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    protected $connection = 'pagos';
    protected $table = 'proveedores';
    protected $fillable = [
        'nombre', 'nit', 'nrc', 'activo', 'aud_usuario'
    ];
    protected $casts = [
        'activo' => 'boolean',
    ];
    public function solicitudesPago() { return $this->hasMany(SolicitudPago::class); }
}
