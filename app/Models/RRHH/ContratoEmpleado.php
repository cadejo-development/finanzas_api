<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class ContratoEmpleado extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'contratos_empleado';

    const ESTADOS = ['borrador', 'activo', 'firmado', 'vencido', 'cancelado'];

    protected $fillable = [
        'empleado_id', 'empleado_nombre',
        'ingreso_id', 'tipo_contrato_id', 'plantilla_id',
        'fecha_inicio', 'fecha_fin', 'estado',
        'notas', 'funciones', 'generado_por_id', 'aud_usuario',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin'    => 'date',
    ];

    public function ingreso()
    {
        return $this->belongsTo(IngresoPersonal::class, 'ingreso_id');
    }

    public function tipoContrato()
    {
        return $this->belongsTo(TipoContrato::class, 'tipo_contrato_id');
    }

    public function plantilla()
    {
        return $this->belongsTo(PlantillaContrato::class, 'plantilla_id');
    }
}
