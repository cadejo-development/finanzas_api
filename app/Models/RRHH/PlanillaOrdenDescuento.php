<?php

namespace App\Models\RRHH;

use App\Models\Empleado;
use Illuminate\Database\Eloquent\Model;

class PlanillaOrdenDescuento extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'planilla_ordenes_descuento';

    protected $fillable = [
        'empleado_id', 'acreedor_id', 'concepto',
        'monto_quincenal', 'fecha_inicio', 'fecha_fin', 'activo',
    ];

    protected $casts = [
        'monto_quincenal' => 'decimal:2',
        'fecha_inicio'    => 'date',
        'fecha_fin'       => 'date',
        'activo'          => 'boolean',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function acreedor()
    {
        return $this->belongsTo(PlanillaAcreedor::class, 'acreedor_id');
    }
}
