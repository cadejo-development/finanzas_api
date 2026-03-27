<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class ExpedienteDireccion extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'expediente_direcciones';

    protected $fillable = [
        'empleado_id', 'tipo', 'departamento_geo',
        'municipio', 'direccion', 'referencia', 'es_principal',
    ];

    protected $casts = [
        'es_principal' => 'boolean',
    ];
}
