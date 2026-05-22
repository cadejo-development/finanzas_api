<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class Planilla extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'planillas';

    protected $fillable = [
        'anio', 'mes', 'quincena', 'fecha_inicio', 'fecha_fin',
        'sucursal_id', 'estado',
        'total_salarios', 'total_descuentos', 'total_patronal', 'total_neto',
        'creado_por',
    ];

    protected $casts = [
        'fecha_inicio'    => 'date',
        'fecha_fin'       => 'date',
        'total_salarios'  => 'decimal:2',
        'total_descuentos'=> 'decimal:2',
        'total_patronal'  => 'decimal:2',
        'total_neto'      => 'decimal:2',
    ];

    public function lineas()
    {
        return $this->hasMany(PlanillaLinea::class, 'planilla_id');
    }
}
