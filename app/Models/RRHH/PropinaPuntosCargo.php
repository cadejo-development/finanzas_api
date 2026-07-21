<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PropinaPuntosCargo extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'propina_puntos_cargo';

    protected $fillable = ['cargo_id', 'puntos_propina', 'notas'];

    protected $casts = ['puntos_propina' => 'decimal:2'];
}
