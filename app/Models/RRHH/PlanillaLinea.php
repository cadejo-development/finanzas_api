<?php

namespace App\Models\RRHH;

use App\Models\Empleado;
use Illuminate\Database\Eloquent\Model;

class PlanillaLinea extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'planilla_lineas';

    protected $fillable = [
        'planilla_id', 'empleado_id',
        'salario_base', 'dias_quincena', 'dias_laborados', 'salario_proporcional',
        'afp_empleado', 'isss_empleado', 'renta', 'otros_descuentos',
        'total_descuentos_empleado', 'salario_neto',
        'afp_patronal', 'isss_patronal', 'insaforp_patronal', 'total_patronal',
        'costo_total', 'detalle_descuentos', 'notas',
    ];

    protected $casts = [
        'salario_base'              => 'decimal:2',
        'dias_laborados'            => 'decimal:2',
        'salario_proporcional'      => 'decimal:2',
        'afp_empleado'              => 'decimal:2',
        'isss_empleado'             => 'decimal:2',
        'renta'                     => 'decimal:2',
        'otros_descuentos'          => 'decimal:2',
        'total_descuentos_empleado' => 'decimal:2',
        'salario_neto'              => 'decimal:2',
        'afp_patronal'              => 'decimal:2',
        'isss_patronal'             => 'decimal:2',
        'insaforp_patronal'         => 'decimal:2',
        'total_patronal'            => 'decimal:2',
        'costo_total'               => 'decimal:2',
        'detalle_descuentos'        => 'array',
    ];

    public function planilla()
    {
        return $this->belongsTo(Planilla::class, 'planilla_id');
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }
}
