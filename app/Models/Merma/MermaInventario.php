<?php

namespace App\Models\Merma;

use Illuminate\Database\Eloquent\Model;

class MermaInventario extends Model
{
    protected $connection = 'compras';
    protected $table      = 'merma_inventarios';

    protected $fillable = [
        'sucursal_id', 'fecha', 'usuario_id',
        'hora_inicio', 'hora_cierre', 'estado',
        'aprobado_por', 'aprobado_at',
    ];

    protected $casts = [
        'fecha'       => 'date',
        'aprobado_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(MermaInvItem::class, 'inventario_id');
    }

    public function entradas()
    {
        return $this->hasMany(MermaEntrada::class, 'inventario_id');
    }

    public function fisica()
    {
        return $this->hasOne(MermaFisica::class, 'inventario_id');
    }

    public function cocina()
    {
        return $this->hasMany(MermaCocina::class, 'inventario_id');
    }

    public function otrosUsos()
    {
        return $this->hasMany(MermaOtroUso::class, 'inventario_id');
    }

    public function ventasBrilo()
    {
        return $this->hasMany(MermaVentaBrilo::class, 'inventario_id');
    }

    public function auditLog()
    {
        return $this->hasMany(MermaAuditLog::class, 'inventario_id');
    }
}
