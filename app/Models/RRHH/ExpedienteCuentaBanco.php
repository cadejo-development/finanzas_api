<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class ExpedienteCuentaBanco extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'expediente_cuentas_banco';

    protected $fillable = [
        'empleado_id',
        'banco',
        'tipo_cuenta',
        'numero_cuenta',
        'titular',
        'es_principal',
        'aud_usuario',
    ];

    protected $casts = [
        'es_principal' => 'boolean',
    ];
}
