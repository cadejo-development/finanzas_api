<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrewRecetaDiaObjetivo extends Model
{
    protected $connection = 'compras';
    protected $table = 'brew_receta_dias_objetivo';

    protected $fillable = [
        'brew_receta_id', 'dia', 'etapa',
        'plato_min', 'plato_obj', 'plato_max',
        'temp_min',  'temp_obj',  'temp_max',
        'ph_min',    'ph_obj',    'ph_max',
        'notas_objetivo',
    ];

    protected $casts = [
        'dia'       => 'integer',
        'plato_min' => 'float', 'plato_obj' => 'float', 'plato_max' => 'float',
        'temp_min'  => 'float', 'temp_obj'  => 'float', 'temp_max'  => 'float',
        'ph_min'    => 'float', 'ph_obj'    => 'float', 'ph_max'    => 'float',
    ];

    public function receta()
    {
        return $this->belongsTo(BrewReceta::class, 'brew_receta_id');
    }
}
