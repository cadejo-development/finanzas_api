<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class ExpedienteIdioma extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'expediente_idiomas';

    protected $fillable = [
        'empleado_id', 'idioma',
        'nivel_habla', 'nivel_escucha', 'nivel_lectura', 'nivel_escritura',
        'notas', 'atestado_ruta',
    ];

    protected $casts = [
        'nivel_habla'     => 'integer',
        'nivel_escucha'   => 'integer',
        'nivel_lectura'   => 'integer',
        'nivel_escritura' => 'integer',
    ];
}
