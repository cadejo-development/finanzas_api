<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecetaCategoria extends Model
{
    protected $connection = 'compras';
    protected $table      = 'receta_categorias';

    protected $fillable = ['nombre', 'key', 'orden', 'activa', 'parent_id'];

    protected $casts = ['activa' => 'boolean'];

    public function recetas()
    {
        return $this->hasMany(Receta::class, 'categoria_id');
    }

    public function padre()
    {
        return $this->belongsTo(RecetaCategoria::class, 'parent_id');
    }

    public function hijos()
    {
        return $this->hasMany(RecetaCategoria::class, 'parent_id');
    }
}
