<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class Traslado extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'traslados';

    protected $fillable = [
        'empleado_id', 'solicitado_por_id',
        'sucursal_origen_id', 'sucursal_origen_nombre',
        'cargo_origen_id', 'cargo_origen_nombre',
        'sucursal_destino_id', 'sucursal_destino_nombre',
        'cargo_destino_id', 'cargo_destino_nombre',
        'fecha_efectiva', 'motivo', 'estado', 'aud_usuario',
    ];

    protected $casts = ['fecha_efectiva' => 'date'];
}
