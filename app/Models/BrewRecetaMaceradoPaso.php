<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewRecetaMaceradoPaso extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_receta_macerado_pasos';
    protected $fillable = ['brew_receta_id', 'orden', 'nombre', 'temp_objetivo', 'tiempo_min'];
}
