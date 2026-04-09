<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstadoReceta extends Model
{
    protected $connection = 'compras';
    protected $table = 'estados_receta';

    protected $fillable = ['codigo', 'nombre', 'color', 'orden'];

    public function recetas()
    {
        return $this->hasMany(Receta::class, 'estado_id');
    }
}
