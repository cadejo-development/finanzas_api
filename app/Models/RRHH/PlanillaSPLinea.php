<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PlanillaSPLinea extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'planilla_sp_lineas';

    protected $fillable = [
        'planilla_sp_id', 'empleado_id', 'nombre', 'nit', 'descripcion_servicio',
        'dias_periodo', 'dias_permiso', 'dias_incapacidad', 'dias_trabajados',
        'monto_honorario', 'retencion_isr', 'liquido', 'notas',
    ];

    protected $casts = [
        'monto_honorario' => 'decimal:2',
        'retencion_isr'   => 'decimal:2',
        'liquido'         => 'decimal:2',
    ];

    public function planilla()
    {
        return $this->belongsTo(PlanillaSP::class, 'planilla_sp_id');
    }
}
