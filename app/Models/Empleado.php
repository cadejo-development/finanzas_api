<?php

namespace App\Models;

use App\Models\RRHH\Plaza;
use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'empleados';

    protected $fillable = [
        'codigo', 'nombres', 'apellidos', 'email',
        'cargo_id', 'plaza_id', 'sucursal_id', 'departamento_id', 'activo', 'aud_usuario',
        'salario_base',
    ];

    protected $casts = [
        'activo'        => 'boolean',
        'fecha_ingreso' => 'date',
        'salario_base'  => 'decimal:2',
    ];

    public function cargo()
    {
        return $this->belongsTo(Cargo::class);
    }

    public function plaza()
    {
        return $this->belongsTo(Plaza::class);
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
