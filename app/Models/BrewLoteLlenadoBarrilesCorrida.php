<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteLlenadoBarrilesCorrida extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_llenado_barriles_corridas';
    protected $fillable = [
        'brew_lote_id', 'numero_corrida', 'fecha',
        'bbt_vol_inicio', 'bbt_vol_fin',
        'barriles_6th', 'barriles_half', 'derrame',
        'eficiencia', 'fg_real', 'co2_psi', 'notas',
    ];
}
