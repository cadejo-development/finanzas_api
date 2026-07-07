<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class TipoAcreedor extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'tipos_acreedor';
    protected $fillable   = ['nombre', 'activo', 'aud_usuario'];

    public function acreedores()
    {
        return $this->hasMany(Acreedor::class, 'tipo_acreedor_id');
    }
}
