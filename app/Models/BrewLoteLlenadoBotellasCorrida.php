<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteLlenadoBotellasCorrida extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_llenado_botellas_corridas';
    protected $fillable = [
        'brew_lote_id', 'numero_corrida', 'fecha',
        'bbt_vol_inicio', 'bbt_vol_fin',
        'botellas_buenas', 'bajo_nivel', 'derrame',
        'vol_llenado_l', 'eficiencia', 'cajas',
        'fg_real', 'co2_vol', 'notas',
    ];
}
