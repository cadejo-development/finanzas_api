<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoDepar extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'geo_departamentos';

    protected $fillable = ['codigo', 'nombre'];

    public function distritos(): HasMany
    {
        return $this->hasMany(GeoDistrito::class, 'departamento_id');
    }
}
