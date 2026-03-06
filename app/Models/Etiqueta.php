<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Etiqueta extends Model
{
    protected $connection = 'pagos';
    protected $table = 'etiquetas';
    protected $fillable = ['codigo', 'nombre'];
}
