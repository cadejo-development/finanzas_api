<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sucursal extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'sucursales';
    protected $fillable = [
        'codigo', 'nombre', 'tipo', 'aud_usuario'
    ];
    public function centrosCosto()
    {
        return $this->hasMany(CentroCosto::class);
    }
    public function pedidos()
    {
        return $this->hasMany(Pedido::class);
    }
}
