<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteCoccion extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_coccion';
    protected $fillable = ['brew_lote_id', 'numero', 'og_real', 'vol_preboil_real', 'vol_postboil_real', 'temp_mash_real', 'tiempo_boil_min', 'notas',
        'ph_mash_real', 'ph_postboil', 'molino_kg_real', 'molino_tiempo_min', 'molino_notas', 'macerado_tiempo_min',
        'whirlpool_hora', 'whirlpool_temp', 'enf_temp_entrada', 'enf_temp_refrigerante',
        'enf_temp_salida', 'enf_tiempo_min', 'oxig_temp', 'oxig_nivel',
    ];
}
