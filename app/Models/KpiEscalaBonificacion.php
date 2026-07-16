<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiEscalaBonificacion extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'kpi_escala_bonificacion';

    protected $fillable = [
        'kpi_plantilla_id',
        'porcentaje_desde',
        'operador',
        'tipo',
        'valor',
        'orden',
    ];

    protected $casts = [
        'porcentaje_desde' => 'float',
        'valor'            => 'float',
        'orden'            => 'integer',
    ];

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(KpiPlantilla::class, 'kpi_plantilla_id');
    }
}
