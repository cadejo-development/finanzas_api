<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class ErrorLog extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'error_logs';

    protected $fillable = [
        'sistema',
        'controlador', 'funcion', 'metodo_http', 'url',
        'tipo_excepcion', 'codigo_http', 'mensaje', 'trace',
        'request_data', 'ip', 'user_agent',
        'usuario_email', 'usuario_id', 'empleado_id',
        'departamento_codigo', 'departamento_nombre',
        'severidad', 'resuelto', 'notas_resolucion',
        'resuelto_at', 'resuelto_por',
    ];

    protected $casts = [
        'request_data' => 'array',
        'resuelto'     => 'boolean',
        'resuelto_at'  => 'datetime',
    ];
}
