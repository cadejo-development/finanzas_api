<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpleadoJefatura extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'empleado_jefaturas';

    protected $fillable = [
        'empleado_id', 'tipo_jefatura_id', 'sucursal_id', 'activo', 'aud_usuario',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function tipoJefatura()
    {
        return $this->belongsTo(TipoJefatura::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }
}
