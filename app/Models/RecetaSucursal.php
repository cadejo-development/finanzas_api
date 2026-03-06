<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecetaSucursal extends Model
{
    protected $connection = 'compras';
    protected $table      = 'receta_sucursal';

    protected $fillable = [
        'receta_id',
        'sucursal_id',
        'platos_semana',
        'activa',
        'aud_usuario',
    ];

    protected $casts = [
        'platos_semana' => 'integer',
        'activa'        => 'boolean',
    ];

    public function receta(): BelongsTo
    {
        return $this->belongsTo(Receta::class);
    }
}
