<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PlanillaMovimiento extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'planilla_movimientos';

    protected $fillable = [
        'planilla_id', 'empleado_id', 'tipo', 'concepto',
        'fecha_inicio', 'fecha_fin', 'dias', 'monto', 'referencia_id', 'estado',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin'    => 'date',
        'dias'         => 'decimal:2',
        'monto'        => 'decimal:2',
    ];

    public function planilla()
    {
        return $this->belongsTo(Planilla::class, 'planilla_id');
    }
}
