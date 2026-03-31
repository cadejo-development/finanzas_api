<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class ExpedienteDireccion extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'expediente_direcciones';

    protected $fillable = [
        'empleado_id', 'tipo',
        'departamento_id', 'distrito_id', 'municipio_id',
        'departamento_geo', 'municipio',
        'direccion', 'referencia', 'es_principal',
        'latitud', 'longitud',
    ];

    protected $casts = [
        'es_principal' => 'boolean',
        'latitud'      => 'float',
        'longitud'     => 'float',
    ];
}
