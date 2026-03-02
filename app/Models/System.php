<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class System extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'systems';
    protected $fillable = [
        'nombre', 'codigo', 'aud_usuario'
    ];
}
