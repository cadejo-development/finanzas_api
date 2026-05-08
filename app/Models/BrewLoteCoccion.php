<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteCoccion extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_coccion';
    protected $fillable = ['brew_lote_id', 'og_real', 'vol_preboil_real', 'vol_postboil_real', 'temp_mash_real', 'tiempo_boil_min', 'notas'];
}
