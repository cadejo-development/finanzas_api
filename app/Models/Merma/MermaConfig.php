<?php

namespace App\Models\Merma;

use Illuminate\Database\Eloquent\Model;

class MermaConfig extends Model
{
    protected $connection = 'compras';
    protected $table      = 'merma_config';

    protected $fillable = [
        'densidad_kg_l', 'tara_pequeno_lb', 'tara_grande_lb',
        'barril_pequeno_oz', 'barril_grande_oz',
        'semaforo_normal', 'semaforo_revisar', 'semaforo_alerta',
        'meta_pct', 'updated_by',
    ];

    protected $casts = [
        'densidad_kg_l'    => 'decimal:4',
        'tara_pequeno_lb'  => 'decimal:2',
        'tara_grande_lb'   => 'decimal:2',
        'barril_pequeno_oz'=> 'decimal:2',
        'barril_grande_oz' => 'decimal:2',
        'semaforo_normal'  => 'decimal:2',
        'semaforo_revisar' => 'decimal:2',
        'semaforo_alerta'  => 'decimal:2',
        'meta_pct'         => 'decimal:2',
    ];
}
