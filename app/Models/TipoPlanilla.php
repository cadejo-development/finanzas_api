<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoPlanilla extends Model
{
    protected $table = 'tipo_planilla';

    protected $fillable = ['slug', 'nombre', 'descripcion', 'activo'];

    public function empleados()
    {
        return $this->belongsToMany(Empleado::class, 'empleado_tipo_planilla', 'tipo_planilla_id', 'empleado_id');
    }
}
