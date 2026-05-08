<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteLlenadoBarril extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_llenado_barriles';
    protected $fillable = ['brew_lote_id', 'fecha', 'barriles_6th', 'barriles_half', 'vol_total_barriles', 'fg_real', 'co2_psi', 'notas'];
}
