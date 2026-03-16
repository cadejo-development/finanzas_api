<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class MotivoDesvinculacion extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'motivos_desvinculacion';

    protected $fillable = ['codigo', 'nombre', 'tipo', 'activo', 'aud_usuario'];

    protected $casts = ['activo' => 'boolean'];

    public function desvinculaciones()
    {
        return $this->hasMany(Desvinculacion::class, 'motivo_id');
    }
}
