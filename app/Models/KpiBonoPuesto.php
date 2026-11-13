<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiBonoPuesto extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'kpi_bono_puesto';

    protected $fillable = [
        'cargo_id',
        'sucursal_id',
        'monto_bono',
        'umbral_minimo',
        'aud_usuario',
    ];

    protected $casts = [
        'cargo_id'      => 'integer',
        'sucursal_id'   => 'integer',
        'monto_bono'    => 'float',
        'umbral_minimo' => 'float',
    ];

    public function cargo(): BelongsTo
    {
        return $this->belongsTo(Cargo::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}
