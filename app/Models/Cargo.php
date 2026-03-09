<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cargo extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'cargos';

    protected $fillable = [
        'codigo', 'nombre', 'activo', 'aud_usuario',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function empleados()
    {
        return $this->hasMany(Empleado::class);
    }
}
