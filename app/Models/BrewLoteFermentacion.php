<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteFermentacion extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_fermentacion';
    protected $fillable = ['brew_lote_id', 'fecha_pitch', 'temp_pitch', 'og_pitch', 'vol_pitch', 'levadura_nombre', 'levadura_cantidad_g', 'notas'];
}
