<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewRecetaBoilPaso extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_receta_boil_pasos';
    protected $fillable = ['brew_receta_id', 'orden', 'descripcion', 'tiempo_min'];
}
