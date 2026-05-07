<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecetaCategoria extends Model
{
    protected $connection = 'compras';
    protected $table      = 'receta_categorias';

    protected $fillable = ['nombre', 'key', 'activa'];

    protected $casts = ['activa' => 'boolean'];

    public function recetas()
    {
        return $this->hasMany(Receta::class, 'categoria_id');
    }
}
