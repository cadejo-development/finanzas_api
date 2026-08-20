<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeoDepartamento extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'geo_departamentos';

    protected $fillable = ['codigo', 'nombre'];
}
