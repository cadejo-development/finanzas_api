<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class ExpedienteExperienciaLaboral extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'expediente_experiencia_laboral';

    protected $fillable = [
        'empleado_id', 'empresa', 'cargo',
        'fecha_inicio', 'fecha_fin', 'es_actual',
        'descripcion', 'pais',
    ];

    protected $casts = [
        'es_actual'   => 'boolean',
        'fecha_inicio' => 'date',
        'fecha_fin'    => 'date',
    ];
}
