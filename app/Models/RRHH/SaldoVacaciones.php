<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class SaldoVacaciones extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'saldos_vacaciones';

    protected $fillable = [
        'empleado_id', 'anio', 'dias_disponibles', 'dias_usados', 'dias_acumulados', 'aud_usuario',
    ];

    protected $casts = [
        'dias_disponibles' => 'decimal:1',
        'dias_usados'      => 'decimal:1',
        'dias_acumulados'  => 'decimal:1',
    ];
}
