<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\TipoPersona;
use App\Models\Contribuyente;

class Proveedor extends Model
{
    protected $connection = 'pagos';
    protected $table = 'proveedores';
    protected $fillable = [
        'codigo', 'nombre', 'nit', 'nrc', 'telefono', 'direccion',
        'cuenta_bancaria', 'tipo_cuenta', 'banco', 'correo',
        'tipo_persona_id', 'tipo_contribuyente_id', 'activo', 'aud_usuario',
    ];
    protected $casts = [
        'activo' => 'boolean',
    ];

    public function tipoPersona()
    {
        return $this->belongsTo(TipoPersona::class, 'tipo_persona_id');
    }

    public function tipoContribuyente()
    {
        return $this->belongsTo(Contribuyente::class, 'tipo_contribuyente_id');
    }

    public function solicitudesPago() { return $this->hasMany(SolicitudPago::class); }
}
