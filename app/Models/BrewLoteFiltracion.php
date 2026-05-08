<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteFiltracion extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_filtracion';
    protected $fillable = ['brew_lote_id', 'vol_bbt_real', 'og_bbt', 'temp_transfer', 'num_corridas', 'notas'];
}
