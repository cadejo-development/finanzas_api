<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Departamento extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'departamentos';

    protected $fillable = [
        'codigo', 'nombre', 'descripcion',
        'parent_id', 'sucursal_id', 'jefe_empleado_id',
        'activo', 'aud_usuario',
    ];

    public function parent()
    {
        return $this->belongsTo(Departamento::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Departamento::class, 'parent_id');
    }
}
