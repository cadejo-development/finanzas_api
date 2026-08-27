<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewRecetaMalta extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_receta_maltas';
    protected $fillable = ['brew_receta_id', 'orden', 'nombre', 'cantidad_lb', 'proveedor', 'molida_1_kg', 'molida_2_kg', 'brew_ingrediente_id'];
}
