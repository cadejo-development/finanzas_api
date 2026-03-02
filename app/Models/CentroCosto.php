<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CentroCosto extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'centros_costo';

    protected $fillable = [
        'codigo',
        'nombre',
        'activo',
        'padre_id',
        'es_sub',
        'aud_usuario',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'es_sub' => 'boolean',
    ];

    // ── Jerarquía ──────────────────────────────────────────────────────────────

    /** Centro de costo padre (agrupador) */
    public function padre()
    {
        return $this->belongsTo(CentroCosto::class, 'padre_id');
    }

    /** Sub-centros / hijos de este agrupador */
    public function hijos()
    {
        return $this->hasMany(CentroCosto::class, 'padre_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────

    /** Solo los centros operativos (hojas, es_sub = true) */
    public function scopeOperativos($query)
    {
        return $query->where('es_sub', true)->where('activo', true);
    }

    /** Solo los agrupadores (nodos padre) */
    public function scopeAgrupadores($query)
    {
        return $query->where('es_sub', false)->where('activo', true);
    }

    public function pedidos()
    {
        return $this->hasMany(Pedido::class, 'centro_costo_id');
    }
}