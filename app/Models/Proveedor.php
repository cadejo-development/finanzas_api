<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    protected $connection = 'pagos';
    protected $table = 'proveedores';
    protected $fillable = [
        'codigo', 'nombre', 'nit', 'nrc', 'telefono', 'direccion', 'cuenta_bancaria', 'tipo_cuenta', 'banco', 'correo', 'activo', 'aud_usuario'
    ];
    protected $casts = [
        'activo' => 'boolean',
    ];
    public function solicitudesPago() { return $this->hasMany(SolicitudPago::class); }
}
