<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PeriodoPruebaTraslado extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'periodos_prueba_traslado';

    protected $fillable = [
        'empleado_id', 'solicitado_por_id',
        'sucursal_origen_id', 'sucursal_origen_nombre',
        'cargo_origen_id', 'cargo_origen_nombre',
        'sucursal_prueba_id', 'sucursal_prueba_nombre',
        'cargo_prueba_id', 'cargo_prueba_nombre',
        'fecha_inicio', 'fecha_fin',
        'estado', 'decision_notas', 'decision_en', 'decision_por',
        'aud_usuario',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin'    => 'date',
        'decision_en'  => 'datetime',
    ];
}
