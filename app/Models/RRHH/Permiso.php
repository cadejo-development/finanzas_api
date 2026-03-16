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
        'dias', 'motivo', 'estado', 'observaciones_jefe', 'aud_usuario',
    ];

    protected $casts = [
        'fecha'           => 'date',
        'es_dia_completo' => 'boolean',
        'horas_solicitadas' => 'decimal:2',
        'dias'            => 'decimal:1',
    ];

    public function tipoPermiso()
    {
        return $this->belongsTo(TipoPermiso::class, 'tipo_permiso_id');
    }
}
