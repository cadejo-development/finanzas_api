<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class ExpedienteDocumento extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'expediente_documentos';

    protected $fillable = [
        'empleado_id', 'tipo', 'numero',
        'fecha_emision', 'fecha_vencimiento', 'entidad_emisora', 'notas',
    ];

    protected $casts = [
        'fecha_emision'     => 'date',
        'fecha_vencimiento' => 'date',
    ];
}
