<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecetaModificador extends Model
{
    protected $connection = 'compras';
    protected $table      = 'receta_modificadores';

    protected $fillable = [
        'receta_id',
        'grupo_id_origen',
        'grupo_codigo',
        'grupo_nombre',
        'opcion_nombre',
        'producto_id',
        'sub_receta_id',
        'cantidad',
        'unidad',
        'aud_usuario',
    ];

    protected $casts = [
        'cantidad' => 'float',
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
