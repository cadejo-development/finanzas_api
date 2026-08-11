<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteIngrediente extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_ingredientes';

    protected $fillable = [
        'brew_lote_id', 'tipo', 'nombre',
        'cantidad_objetivo', 'unidad', 'detalle',
        'estado', 'notas', 'cantidad_real',
    ];

    public function lote() { return $this->belongsTo(BrewLote::class, 'brew_lote_id'); }
}
