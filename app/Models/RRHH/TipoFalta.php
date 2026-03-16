<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class TipoFalta extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'tipos_falta';

    protected $fillable = ['codigo', 'nombre', 'gravedad', 'activo', 'aud_usuario'];

    protected $casts = ['activo' => 'boolean'];

    public function amonestaciones()
    {
        return $this->hasMany(Amonestacion::class, 'tipo_falta_id');
    }
}
