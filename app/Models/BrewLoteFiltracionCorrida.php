<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteFiltracionCorrida extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_filtracion_corridas';
    protected $fillable = [
        'brew_lote_id', 'numero_corrida',
        'vol_litros', 'vol_inicial', 'vol_final',
        'tierra_blanca', 'tierra_roja',
        'densidad', 'hora', 'timestamp_inicio', 'notas',
    ];
}
