<?php

namespace App\Models\Merma;

use Illuminate\Database\Eloquent\Model;

class MermaOtroUso extends Model
{
    protected $connection = 'compras';
    protected $table      = 'merma_otros_usos';
    public    $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'inventario_id', 'cerveza_id',
        'cantidad', 'unidad', 'oz_calculado',
        'categoria', 'detalle', 'usuario_captura',
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
