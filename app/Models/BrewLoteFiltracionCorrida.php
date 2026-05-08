<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteFiltracionCorrida extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_filtracion_corridas';
    protected $fillable = ['brew_lote_id', 'numero_corrida', 'vol_litros', 'densidad', 'hora', 'notas'];
}
