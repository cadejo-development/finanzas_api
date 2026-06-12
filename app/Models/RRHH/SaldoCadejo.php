<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class SaldoCadejo extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'saldos_cadejo';

    protected $fillable = [
        'empleado_id', 'anio',
        'dias_disponibles', 'dias_usados',
        'aud_usuario',
    ];

    protected $casts = [
        'dias_disponibles' => 'decimal:1',
        'dias_usados'      => 'decimal:1',
    ];

    public function getDiasRestantesAttribute(): float
    {
        return max((float) $this->dias_disponibles - (float) $this->dias_usados, 0);
    }
}
