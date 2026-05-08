<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewReceta extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_recetas';

    protected $fillable = [
        'nombre', 'estilo', 'codigo', 'vol_preboil', 'vol_postboil', 'vol_bbt',
        'og', 'fg', 'abv', 'ibu', 'srm', 'eficiencia_macerado', 'dias_ferm',
        'notas', 'activa',
    ];

    protected $casts = ['activa' => 'boolean'];

    public function maltas()    { return $this->hasMany(BrewRecetaMalta::class, 'brew_receta_id')->orderBy('orden'); }
    public function lupulos()   { return $this->hasMany(BrewRecetaLupulo::class, 'brew_receta_id')->orderBy('orden'); }
    public function minerales() { return $this->hasMany(BrewRecetaMineral::class, 'brew_receta_id')->orderBy('orden'); }
    public function levaduras() { return $this->hasMany(BrewRecetaLevadura::class, 'brew_receta_id'); }
    public function maceradoPasos() { return $this->hasMany(BrewRecetaMaceradoPaso::class, 'brew_receta_id')->orderBy('orden'); }
    public function boilPasos() { return $this->hasMany(BrewRecetaBoilPaso::class, 'brew_receta_id')->orderBy('orden'); }
    public function lotes()     { return $this->hasMany(BrewLote::class, 'brew_receta_id'); }
}
