<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PlanillaSP extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'planillas_sp';

    protected $fillable = [
        'anio', 'mes', 'descripcion', 'sucursal_id', 'estado',
        'total_honorarios', 'total_retencion', 'total_neto', 'creado_por',
    ];

    protected $casts = [
        'total_honorarios' => 'decimal:2',
        'total_retencion'  => 'decimal:2',
        'total_neto'       => 'decimal:2',
    ];

    public function lineas()
    {
        return $this->hasMany(PlanillaSPLinea::class, 'planilla_sp_id');
    }
}
