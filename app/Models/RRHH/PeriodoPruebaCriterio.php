<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PeriodoPruebaCriterio extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'periodos_prueba_criterios';

    protected $fillable = ['orden', 'pregunta', 'descripcion', 'activo'];

    protected $casts = ['activo' => 'boolean'];
}
