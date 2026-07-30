<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class Permiso extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'permisos';

    protected $fillable = [
        'empleado_id', 'jefe_id', 'tipo_permiso_id',
        'fecha', 'es_dia_completo', 'hora_inicio', 'hora_fin', 'horas_solicitadas',
        'dias', 'motivo', 'relacion_familiar', 'fecha_evento',
        'estado', 'observaciones_jefe',
        'aprobado_por', 'aprobado_at',
        'archivo_nombre', 'archivo_ruta',
        'doc_posterior_pendiente',
        'aud_usuario', 'creado_por',
    ];

    protected $casts = [
        'fecha'                   => 'date',
        'fecha_evento'            => 'date',
        'es_dia_completo'         => 'boolean',
        'horas_solicitadas'       => 'decimal:2',
        'dias'                    => 'decimal:1',
        'doc_posterior_pendiente' => 'boolean',
        'aprobado_at'             => 'datetime',
    ];

    public function tipoPermiso()
    {
        return $this->belongsTo(TipoPermiso::class, 'tipo_permiso_id');
    }
}
