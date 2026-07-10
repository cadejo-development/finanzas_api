<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class SolicitudRetractacion extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'solicitudes_retractacion';

    protected $fillable = [
        'desvinculacion_id',
        'empleado_id', 'empleado_nombre', 'sucursal_nombre', 'cargo_nombre',
        'solicitado_por_empleado_id', 'solicitado_por_nombre',
        'justificacion',
        'estado',
        'revisado_por_empleado_id', 'revisado_por_nombre',
        'revisado_en', 'comentario_revision',
        'aud_usuario',
    ];

    protected $casts = ['revisado_en' => 'datetime'];

    public function desvinculacion()
    {
        return $this->belongsTo(Desvinculacion::class, 'desvinculacion_id');
    }
}
