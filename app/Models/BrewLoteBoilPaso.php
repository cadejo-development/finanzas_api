<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteBoilPaso extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_boil_pasos';
    protected $fillable = [
        'brew_lote_id', 'orden', 'descripcion', 'tiempo_min', 'hora', 'completado', 'fase',
        'cantidad_objetivo', 'unidad', 'plato_objetivo', 'vol_objetivo_l',
        'timestamp_adicion', 'ingrediente_real', 't_transcu_real',
        'cantidad_real', 'plato_real', 'vol_real_l', 'notas',
    ];
    protected $casts = ['completado' => 'boolean'];
}
