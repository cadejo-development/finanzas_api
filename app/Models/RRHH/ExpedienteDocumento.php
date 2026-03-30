<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class ExpedienteDocumento extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'expediente_documentos';

    protected $fillable = [
        'empleado_id', 'tipo', 'numero',
        'fecha_emision', 'fecha_vencimiento',
        'lugar_exp_municipio_id', 'lugar_exp_texto',
        'foto_frente_ruta', 'foto_reverso_ruta',
    ];

    protected $casts = [
        'fecha_emision'     => 'date',
        'fecha_vencimiento' => 'date',
    ];
}
