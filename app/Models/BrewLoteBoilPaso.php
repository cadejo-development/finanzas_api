<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteBoilPaso extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_boil_pasos';
    protected $fillable = [
        'brew_lote_id', 'orden', 'descripcion', 'tiempo_min', 'hora', 'completado', 'fase',
        'cantidad_objetivo', 'unidad',
        'timestamp_adicion', 'cantidad_real', 'plato_real', 'vol_real_l', 'notas',
    ];
    protected $casts = ['completado' => 'boolean'];
}
