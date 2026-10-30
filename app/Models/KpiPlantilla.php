<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KpiPlantilla extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'kpi_plantillas';

    protected $fillable = [
        'nombre',
        'descripcion',
        'comentarios',
        'sucursal_id',
        'departamento_id',
        'unidad_medida',
        'monto_objetivo',
        'monto_bono_base',
        'tipo_meta',
        'operador_cumplimiento',
        'activo',
        'tiene_bono',
        'periodicidad',
        'cargo_responsable_id',
        'vigencia_desde',
        'vigencia_hasta',
        'aud_usuario',
    ];

    protected $casts = [
        'activo'               => 'boolean',
        'tiene_bono'           => 'boolean',
        'sucursal_id'          => 'integer',
        'departamento_id'      => 'integer',
        'cargo_responsable_id' => 'integer',
        'monto_bono_base'      => 'float',
        'vigencia_desde'       => 'date',
        'vigencia_hasta'       => 'date',
    ];

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Departamento::class);
    }

    public function escala(): HasMany
    {
        return $this->hasMany(KpiEscalaBonificacion::class, 'kpi_plantilla_id')->orderBy('porcentaje_desde');
    }

    public function cargos(): BelongsToMany
    {
        return $this->belongsToMany(Cargo::class, 'kpi_plantilla_cargos', 'kpi_plantilla_id', 'cargo_id')
                    ->withPivot('peso')
                    ->withTimestamps();
    }

    public function cargoResponsable(): BelongsTo
    {
        return $this->belongsTo(Cargo::class, 'cargo_responsable_id');
    }
}
