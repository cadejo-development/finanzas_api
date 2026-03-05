<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sucursal extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'sucursales';
    protected $fillable = [
        'codigo', 'nombre', 'aud_usuario'
    ];
    public function pedidos()
    {
        return $this->hasMany(Pedido::class);
    }
}
