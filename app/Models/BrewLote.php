<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLote extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_lotes';

    protected $fillable = [
        'brew_receta_id', 'codigo_lote', 'fecha_coccion', 'estado', 'cervecero', 'notas',
    ];

    public function receta()         { return $this->belongsTo(BrewReceta::class, 'brew_receta_id'); }
    public function coccion()        { return $this->hasOne(BrewLoteCoccion::class, 'brew_lote_id'); }
    public function filtracion()     { return $this->hasOne(BrewLoteFiltracion::class, 'brew_lote_id'); }
    public function filtracionCorridas() { return $this->hasMany(BrewLoteFiltracionCorrida::class, 'brew_lote_id'); }
    public function fermentacion()   { return $this->hasOne(BrewLoteFermentacion::class, 'brew_lote_id'); }
    public function fermSeguimiento() { return $this->hasMany(BrewLoteFermSeguimiento::class, 'brew_lote_id')->orderBy('dia'); }
    public function llenadoBotellas() { return $this->hasOne(BrewLoteLlenadoBotellas::class, 'brew_lote_id'); }
    public function llenadoBarriles() { return $this->hasOne(BrewLoteLlenadoBarril::class, 'brew_lote_id'); }
    public function maceradoPasos()  { return $this->hasMany(BrewLoteMaceradoPaso::class, 'brew_lote_id')->orderBy('orden'); }
    public function boilPasos()      { return $this->hasMany(BrewLoteBoilPaso::class, 'brew_lote_id')->orderBy('orden'); }
}
