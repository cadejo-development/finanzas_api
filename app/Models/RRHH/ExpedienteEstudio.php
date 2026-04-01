<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class ExpedienteEstudio extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'expediente_estudios';

    protected $fillable = [
        'empleado_id', 'nivel', 'especializacion', 'titulo', 'institucion',
        'pais', 'anio_inicio', 'anio_graduacion', 'graduado', 'notas',
        'atestado_ruta', 'atestado_mime',
    ];

    protected $casts = [
        'graduado'        => 'boolean',
        'anio_inicio'     => 'integer',
        'anio_graduacion' => 'integer',
    ];
}
