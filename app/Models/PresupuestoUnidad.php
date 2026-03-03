<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PresupuestoUnidad extends Model
{
    protected $connection = 'pagos';
    protected $table = 'presupuestos_unidad';
    protected $fillable = [
        'centro_costo_codigo', 'anio', 'presupuesto_total', 'ejecutado', 'aud_usuario'
    ];
    protected $casts = [
        'presupuesto_total' => 'decimal:2',
        'ejecutado'        => 'decimal:2',
        'anio'             => 'integer',
    ];
}
