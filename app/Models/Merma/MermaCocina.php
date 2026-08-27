<?php

namespace App\Models\Merma;

use Illuminate\Database\Eloquent\Model;

class MermaCocina extends Model
{
    protected $connection = 'compras';
    protected $table      = 'merma_cocina';
    public    $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'inventario_id', 'cerveza_id',
        'cantidad', 'unidad', 'oz_calculado',
        'motivo', 'hora', 'usuario_captura',
    ];

    protected $casts = [
        'cantidad'     => 'decimal:3',
        'oz_calculado' => 'decimal:2',
    ];

    public function cerveza()
    {
        return $this->belongsTo(MermaCerveza::class, 'cerveza_id');
    }
}
