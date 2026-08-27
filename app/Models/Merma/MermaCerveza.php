<?php

namespace App\Models\Merma;

use Illuminate\Database\Eloquent\Model;

class MermaCerveza extends Model
{
    protected $connection = 'compras';
    protected $table      = 'merma_cervezas';

    protected $fillable = [
        'nombre', 'estilo', 'color_hex', 'estado',
        'vigencia_desde', 'vigencia_hasta', 'orden',
    ];

    protected $casts = [
        'vigencia_desde' => 'date',
        'vigencia_hasta' => 'date',
    ];

    public function scopeActivas($query)
    {
        return $query->where(function ($q) {
            $q->where('estado', 'activo')
              ->orWhere(function ($q2) {
                  $q2->where('estado', 'temporada')
                     ->whereDate('vigencia_desde', '<=', today())
                     ->whereDate('vigencia_hasta', '>=', today());
              });
        });
    }
}
