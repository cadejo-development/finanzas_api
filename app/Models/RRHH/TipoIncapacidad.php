<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class TipoIncapacidad extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'tipos_incapacidad';

    protected $fillable = ['codigo', 'nombre', 'activo', 'aud_usuario'];

    protected $casts = ['activo' => 'boolean'];

    public function incapacidades()
    {
        return $this->hasMany(Incapacidad::class, 'tipo_incapacidad_id');
    }
}
