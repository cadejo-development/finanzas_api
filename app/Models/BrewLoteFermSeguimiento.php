<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteFermSeguimiento extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_ferm_seguimiento';
    protected $fillable = ['brew_lote_id', 'dia', 'fecha', 'gravedad', 'temp', 'ph', 'notas'];
}
