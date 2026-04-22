<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class AusenciaInjustificada extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'ausencias_injustificadas';

    protected $fillable = [
        'empleado_id', 'registrado_por_id', 'fecha', 'descripcion',
        'cubierta_por_incapacidad_id', 'aud_usuario',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];
}
