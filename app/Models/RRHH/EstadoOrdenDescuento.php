<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class EstadoOrdenDescuento extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'estados_orden_descuento';
    protected $fillable   = ['nombre', 'color', 'activo', 'aud_usuario'];
}
