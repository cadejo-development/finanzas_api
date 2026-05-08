<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteBoilPaso extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_boil_pasos';
    protected $fillable = ['brew_lote_id', 'orden', 'descripcion', 'tiempo_min', 'hora', 'completado'];
    protected $casts = ['completado' => 'boolean'];
}
