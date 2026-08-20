<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class HorasExtrasSolicitud extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'horas_extras_solicitudes';

    protected $fillable = [
        'empleado_id',
        'solicitado_por_empleado_id',
        'departamento_id',
        'fecha_inicio',
        'fecha_fin',
        'horas',
        'descripcion',
        'estado',
        'n1_empleado_id',
        'n1_aprobado_por_id',
        'n1_fecha',
        'n1_observaciones',
        'n2_requerido',
        'n2_empleado_id',
        'n2_aprobado_por_id',
        'n2_fecha',
        'n2_observaciones',
        'quincena_trabajo_anio',
        'quincena_trabajo_mes',
        'quincena_trabajo_num',
        'quincena_pago_anio',
        'quincena_pago_mes',
        'quincena_pago_num',
        'rechazo_observaciones',
        'aud_usuario',
    ];

    protected $casts = [
        'fecha_inicio'  => 'date',
        'fecha_fin'     => 'date',
        'horas'         => 'decimal:2',
        'n1_fecha'      => 'datetime',
        'n2_fecha'      => 'datetime',
        'n2_requerido'  => 'boolean',
    ];
}
