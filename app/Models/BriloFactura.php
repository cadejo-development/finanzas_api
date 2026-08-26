<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BriloFactura extends Model
{
    protected $connection = 'pagos';
    protected $table      = 'brilo_facturas';

    protected $casts = [
        'fecha_doc'    => 'date',
        'fecha_creado' => 'datetime',
        'synced_at'    => 'datetime',
        'monto_afecto' => 'decimal:2',
        'monto_exento' => 'decimal:2',
    ];
}
