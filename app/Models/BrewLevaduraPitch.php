<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLevaduraPitch extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_levadura_pitches';
    protected $fillable = [
        'brew_levadura_lote_id', 'brew_lote_id',
        'numero_generacion', 'fecha',
        'vol_pitch_ml', 'viabilidad_pct',
        'og_pitch', 'temp_pitch',
        'costo_estimado_usd', 'notas',
    ];

    public function levaduraLote()
    {
        return $this->belongsTo(BrewLevaduraLote::class, 'brew_levadura_lote_id');
    }

    public function lote()
    {
        return $this->belongsTo(BrewLote::class, 'brew_lote_id');
    }
}
