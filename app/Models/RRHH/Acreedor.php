<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class Acreedor extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'acreedores';
    protected $fillable   = ['tipo_acreedor_id', 'nombre', 'activo', 'aud_usuario'];

    public function tipo()
    {
        return $this->belongsTo(TipoAcreedor::class, 'tipo_acreedor_id');
    }

    public function ordenes()
    {
        return $this->hasMany(OrdenDescuento::class, 'acreedor_id');
    }
}
