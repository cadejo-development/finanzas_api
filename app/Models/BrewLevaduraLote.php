<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewLevaduraLote extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_levadura_lotes';
    protected $fillable = [
        'cepa', 'codigo_lab', 'proveedor',
        'precio_total_usd', 'vol_total_ml',
        'fecha_inicio', 'fecha_vencimiento',
        'activo', 'notas',
    ];

    protected $casts = ['activo' => 'boolean'];

    public function pitches()
    {
        return $this->hasMany(BrewLevaduraPitch::class, 'brew_levadura_lote_id')->orderBy('fecha');
    }

    public function getVolUsadoMlAttribute(): float
    {
        return (float) $this->pitches->sum('vol_pitch_ml');
    }

    public function getVolRestanteMlAttribute(): ?float
    {
        if ($this->vol_total_ml === null) return null;
        return max(0, (float) $this->vol_total_ml - $this->vol_usado_ml);
    }

    public function getCostoPorMlAttribute(): ?float
    {
        if (!$this->precio_total_usd || !$this->vol_total_ml) return null;
        return (float) $this->precio_total_usd / (float) $this->vol_total_ml;
    }
}
