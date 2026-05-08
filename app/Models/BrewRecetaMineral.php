<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewRecetaMineral extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_receta_minerales';
    protected $fillable = ['brew_receta_id', 'orden', 'nombre', 'cantidad_g', 'fase'];
}
