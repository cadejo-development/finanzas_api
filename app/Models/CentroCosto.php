<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CentroCosto extends Model
{
    protected $table = 'centros_costo';
    protected $fillable = [
        'codigo', 'nombre', 'activo', 'aud_usuario'
    ];
    protected $casts = [
        'activo' => 'boolean',
    ];
    public function pedidos()
    {
        return $this->hasMany(Pedido::class, 'centro_costo_id');
    }
}
