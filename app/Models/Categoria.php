<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Categoria extends Model
{
    protected $table = 'categorias';
    protected $fillable = [
        'key', 'nombre', 'orden', 'activo', 'aud_usuario'
    ];
    protected $casts = [
        'orden' => 'integer',
        'activo' => 'boolean',
    ];
    public function productos()
    {
        return $this->hasMany(Producto::class);
    }
}
