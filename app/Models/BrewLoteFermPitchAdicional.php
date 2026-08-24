<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLoteFermPitchAdicional extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lote_ferm_pitches_adicionales';

    protected $fillable = [
        'brew_lote_id', 'fecha', 'tipo',
        'levadura_nombre', 'cantidad', 'unidad',
        'brew_levadura_lote_id', 'motivo', 'notas',
    ];

    public function lote()         { return $this->belongsTo(BrewLote::class, 'brew_lote_id'); }
    public function levaduraLote() { return $this->belongsTo(BrewLevaduraLote::class, 'brew_levadura_lote_id'); }
}
