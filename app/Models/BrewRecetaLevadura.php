<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewRecetaLevadura extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_receta_levaduras';
    protected $fillable = ['brew_receta_id', 'nombre', 'codigo', 'proveedor', 'cantidad_g', 'temp_min', 'temp_max'];
}
