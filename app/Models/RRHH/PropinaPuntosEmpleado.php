<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PropinaPuntosEmpleado extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'propina_puntos_empleado';

    protected $fillable = [
        'empleado_id', 'puntos_propina',
        'fecha_desde', 'fecha_hasta', 'motivo', 'registrado_por',
    ];

    protected $casts = [
        'puntos_propina' => 'decimal:2',
        'fecha_desde'    => 'date',
        'fecha_hasta'    => 'date',
    ];
}
