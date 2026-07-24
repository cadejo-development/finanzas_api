<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiResultado extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'kpi_resultados';

    protected $fillable = [
        'kpi_plantilla_id',
        'empleado_id',
        'periodo',
        'porcentaje_cumplimiento',
        'valor_real',
        'meta_periodo',
        'monto_bono',
        'bonificacion_id',
        'aud_usuario',
    ];

    protected $casts = [
        'porcentaje_cumplimiento' => 'float',
        'valor_real'              => 'float',
        'meta_periodo'            => 'float',
        'monto_bono'              => 'float',
        'periodo'                 => 'date',
    ];

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(KpiPlantilla::class, 'kpi_plantilla_id');
    }
}
