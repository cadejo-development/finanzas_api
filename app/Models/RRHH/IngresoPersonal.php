<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class IngresoPersonal extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'ingresos_personal';

    protected $fillable = [
        'empleado_id', 'registrado_por_id',
        'empleado_nombre', 'cargo_nombre', 'sucursal_nombre',
        'fecha_ingreso',
        'confirmacion', 'confirmado_en', 'confirmado_por_id',
        'nueva_fecha_ingreso', 'observaciones',
        'aud_usuario', 'notificado_admin_en',
    ];

    protected $casts = [
        'fecha_ingreso'        => 'date',
        'nueva_fecha_ingreso'  => 'date',
        'confirmado_en'        => 'datetime',
        'notificado_admin_en'  => 'datetime',
    ];

    public function periodoPrueba()
    {
        return $this->hasOne(PeriodoPrueba::class, 'ingreso_id');
    }
}
