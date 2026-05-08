<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteLlenadoBotellas extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_llenado_botellas';
    protected $fillable = ['brew_lote_id', 'fecha', 'vol_inicio', 'vol_fin', 'botellas_buenas', 'botellas_rotas', 'fg_real', 'co2_vol', 'notas'];
}
