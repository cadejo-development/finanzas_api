<?php

namespace App\Models\Merma;

use Illuminate\Database\Eloquent\Model;

class MermaFisica extends Model
{
    protected $connection = 'compras';
    protected $table      = 'merma_fisica';
    public    $timestamps = false;

    const UPDATED_AT = 'updated_at';

    protected $fillable = ['inventario_id', 'cantidad', 'unidad', 'oz_calculado', 'confirmada'];

    protected $casts = [
        'cantidad'     => 'decimal:3',
        'oz_calculado' => 'decimal:2',
        'confirmada'   => 'boolean',
    ];
}
