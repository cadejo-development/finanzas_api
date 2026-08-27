<?php

namespace App\Models\Merma;

use Illuminate\Database\Eloquent\Model;

class MermaPresentacion extends Model
{
    protected $connection = 'compras';
    protected $table      = 'merma_presentaciones';
    public    $timestamps = false;

    protected $fillable = ['presentacion', 'oz_nominal', 'oz_efectivas', 'activa', 'orden'];

    protected $casts = [
        'oz_nominal'   => 'decimal:3',
        'oz_efectivas' => 'decimal:3',
        'activa'       => 'boolean',
    ];
}
