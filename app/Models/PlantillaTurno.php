<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlantillaTurno extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'plantillas_turno';
    protected $fillable   = ['sucursal_id', 'nombre', 'hora_inicio', 'hora_fin', 'activo', 'aud_usuario'];
    protected $casts      = ['activo' => 'boolean'];
}
