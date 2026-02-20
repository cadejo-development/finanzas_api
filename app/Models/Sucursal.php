<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sucursal extends Model
{
    protected $table = 'sucursales';
    protected $fillable = [
        'codigo', 'nombre', 'activo', 'aud_usuario'
    ];
    protected $casts = [
        'activo' => 'boolean',
    ];
    public function pedidos()
    {
        return $this->hasMany(Pedido::class);
    }
}
