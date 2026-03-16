<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class Vacacion extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'vacaciones';

    protected $fillable = [
        'empleado_id', 'jefe_id',
        'fecha_inicio', 'fecha_fin', 'dias',
        'estado', 'observaciones', 'aud_usuario',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin'    => 'date',
        'dias'         => 'decimal:1',
    ];
}
