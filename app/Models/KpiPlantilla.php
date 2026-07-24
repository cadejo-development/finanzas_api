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
        'sucursal_id',
        'departamento_id',
        'unidad_medida',
        'monto_objetivo',
        'tipo_meta',
        'activo',
        'aud_usuario',
    ];

    protected $casts = [
        'activo'          => 'boolean',
        'sucursal_id'     => 'integer',
        'departamento_id' => 'integer',
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
                    ->withTimestamps();
    }
}
