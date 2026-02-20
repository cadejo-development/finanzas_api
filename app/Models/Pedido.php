<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pedido extends Model
{
    protected $table = 'pedidos';
    protected $fillable = [
        'sucursal_id', 'centro_costo_id', 'semana_inicio', 'semana_fin', 'estado', 'total_estimado', 'aud_usuario'
    ];
    protected $casts = [
        'semana_inicio' => 'date',
        'semana_fin' => 'date',
        'total_estimado' => 'decimal:2',
    ];
    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }
    public function centroCosto()
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }
    public function detalles()
    {
        return $this->hasMany(PedidoDetalle::class);
    }
}
