<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewRecetaLupulo extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_receta_lupulos';
    protected $fillable = ['brew_receta_id', 'orden', 'nombre', 'cantidad_g', 'alpha', 'uso', 'tiempo_min'];
}
