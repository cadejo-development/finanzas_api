<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoJefatura extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'tipos_jefatura';

    protected $fillable = [
        'codigo', 'nombre', 'descripcion', 'activo', 'aud_usuario',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function empleadoJefaturas()
    {
        return $this->hasMany(EmpleadoJefatura::class);
    }
}
