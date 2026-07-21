<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PropinaSobrante extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'propina_sobrantes';

    protected $fillable = [
        'sucursal_id', 'periodo_origen_id',
        'monto_original', 'monto_distribuido', 'monto_pendiente',
        'periodo_distribucion_id', 'estado',
    ];

    protected $casts = [
        'monto_original'    => 'decimal:2',
        'monto_distribuido' => 'decimal:2',
        'monto_pendiente'   => 'decimal:2',
    ];
}
