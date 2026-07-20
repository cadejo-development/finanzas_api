<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteMaceradoPaso extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_macerado_pasos';
    protected $fillable = [
        'brew_lote_id', 'orden', 'nombre', 'temp_objetivo', 'temp_real', 'tiempo_min',
        'hora_inicio', 'hora_fin', 'ph_real',
        'ph_objetivo', 'adicion_agua_l', 'vol_agua_real_l', 'adiciones_reales', 'notas',
    ];
}
