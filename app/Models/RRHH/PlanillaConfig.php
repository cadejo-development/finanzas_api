<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PlanillaConfig extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'planilla_config';

    protected $fillable = ['clave', 'valor', 'descripcion'];

    protected $casts = [
        'valor' => 'decimal:4',
    ];
}
