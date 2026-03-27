<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class ExpedienteDatosPersonales extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'expediente_datos_personales';

    protected $fillable = [
        'empleado_id', 'fecha_nacimiento', 'genero', 'estado_civil',
        'nacionalidad', 'grupo_sanguineo', 'lugar_nacimiento',
        'notas', 'aud_usuario',
    ];

    protected $casts = [
        'fecha_nacimiento' => 'date',
    ];
}
