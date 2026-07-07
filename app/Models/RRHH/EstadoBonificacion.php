<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class EstadoBonificacion extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'estados_bonificacion';
    protected $fillable   = ['nombre', 'color', 'activo', 'aud_usuario'];
}
