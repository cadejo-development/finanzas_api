<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class ExpedienteEstudio extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'expediente_estudios';

    protected $fillable = [
        'empleado_id', 'nivel', 'titulo', 'institucion',
        'pais', 'anio_inicio', 'anio_graduacion', 'graduado', 'notas',
    ];

    protected $casts = [
        'graduado'        => 'boolean',
        'anio_inicio'     => 'integer',
        'anio_graduacion' => 'integer',
    ];
}
