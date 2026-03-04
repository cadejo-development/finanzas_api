<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Receta extends Model
{
    protected $connection = 'compras';
    protected $table = 'recetas';

    protected $fillable = [
        'nombre',
        'descripcion',
        'tipo',
        'platos_semana',
        'activa',
        'aud_usuario',
    ];

    protected $casts = [
        'platos_semana' => 'integer',
        'activa'        => 'boolean',
    ];

    /**
     * Ingredientes de la receta (con producto cargado).
     */
    public function ingredientes()
    {
        return $this->hasMany(RecetaIngrediente::class);
    }

    /**
     * Ingredientes con los datos del producto incluidos.
     */
    public function ingredientesConProducto()
    {
        return $this->hasMany(RecetaIngrediente::class)->with('producto');
    }
}
