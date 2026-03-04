<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VentaSemanal extends Model
{
    protected $connection = 'compras';
    protected $table = 'ventas_semanales';

    protected $fillable = [
        'sucursal_id',
        'semana_inicio',
        'archivo_nombre',
        'importado_por',
    ];

    protected $casts = [
        'semana_inicio' => 'date',
    ];

    public function detalles(): HasMany
    {
        return $this->hasMany(VentaSemanalDetalle::class, 'venta_semanal_id');
    }
}
