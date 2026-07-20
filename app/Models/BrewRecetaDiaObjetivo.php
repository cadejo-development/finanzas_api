<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewRecetaDiaObjetivo extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_receta_dias_objetivo';

    protected $fillable = [
        'brew_receta_id', 'dia', 'etapa',
        'plato_obj', 'temp_obj', 'ph_obj', 'notas_objetivo',
    ];

    protected $casts = [
        'dia'       => 'integer',
        'plato_obj' => 'float',
        'temp_obj'  => 'float',
        'ph_obj'    => 'float',
    ];

    public function receta()
    {
        return $this->belongsTo(BrewReceta::class, 'brew_receta_id');
    }
}
