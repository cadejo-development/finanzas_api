<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeoMunicipio extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'geo_municipios';

    protected $fillable = ['departamento_id', 'distrito_id', 'codigo', 'nombre'];
}
