<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PeriodoPrueba extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'periodos_prueba';

    const ESTADOS = ['en_prueba', 'aprobado', 'no_aprobado', 'renuncia', 'desvinculado'];
    const DIAS_DEFAULT = 30;

    protected $fillable = [
        'ingreso_id', 'empleado_id',
        'fecha_inicio', 'fecha_fin_estimada', 'responsable_id',
        'estado', 'comentarios',
        'evaluado_en', 'evaluado_por_id',
        'alerta_15_enviada', 'alerta_7_enviada', 'alerta_3_enviada', 'alerta_sin_eval_enviada',
        'aud_usuario',
    ];

    protected $casts = [
        'fecha_inicio'           => 'date',
        'fecha_fin_estimada'     => 'date',
        'evaluado_en'            => 'datetime',
        'alerta_15_enviada'      => 'boolean',
        'alerta_7_enviada'       => 'boolean',
        'alerta_3_enviada'       => 'boolean',
        'alerta_sin_eval_enviada'=> 'boolean',
    ];

    public function ingreso()
    {
        return $this->belongsTo(IngresoPersonal::class, 'ingreso_id');
    }

    public function estaActivo(): bool
    {
        return $this->estado === 'en_prueba';
    }
}
