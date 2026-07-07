<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class TipoBonificacion extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'tipos_bonificacion';
    protected $fillable   = ['nombre', 'gravado', 'activo', 'aud_usuario'];

    protected $casts = ['gravado' => 'boolean', 'activo' => 'boolean'];
}
