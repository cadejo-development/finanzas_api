<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'empleados';

    protected $fillable = [
        'codigo', 'nombres', 'apellidos', 'email',
        'cargo_id', 'sucursal_id', 'departamento_id', 'activo', 'aud_usuario',
    ];

    protected $casts = [
        'activo'        => 'boolean',
        'fecha_ingreso' => 'date',
    ];

    public function cargo()
    {
        return $this->belongsTo(Cargo::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function departamento()
    {
        return $this->belongsTo(Departamento::class);
    }

    public function jefaturas()
    {
        return $this->hasMany(EmpleadoJefatura::class);
    }
}
