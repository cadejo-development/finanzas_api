<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoSucursal extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'tipos_sucursal';

    protected $fillable = ['codigo', 'nombre'];

    public function sucursales()
    {
        return $this->hasMany(Sucursal::class);
    }
}
