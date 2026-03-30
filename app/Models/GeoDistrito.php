<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoDistrito extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'geo_distritos';

    protected $fillable = ['departamento_id', 'codigo', 'nombre'];

    public function municipios(): HasMany
    {
        return $this->hasMany(GeoMunicipio::class, 'distrito_id');
    }
}
