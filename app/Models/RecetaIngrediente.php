<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecetaIngrediente extends Model
{
    protected $connection = 'compras';
    protected $table = 'receta_ingredientes';

    protected $fillable = [
        'receta_id',
        'producto_id',
        'sub_receta_id',
        'cantidad_por_plato',
        'unidad',
        'aud_usuario',
    ];

    protected $casts = [
        'cantidad_por_plato' => 'decimal:4',
    ];

    public function receta()
    {
        return $this->belongsTo(Receta::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function subReceta()
    {
        return $this->belongsTo(Receta::class, 'sub_receta_id');
    }
}
