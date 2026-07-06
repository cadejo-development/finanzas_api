<?php

namespace App\Models\RRHH;

use App\Models\Cargo;
use App\Models\Departamento;
use App\Models\Empleado;
use Illuminate\Database\Eloquent\Model;

class Plaza extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'plazas';

    protected $fillable = [
        'cargo_id', 'departamento_id', 'codigo', 'activo', 'aud_usuario',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function cargo()
    {
        return $this->belongsTo(Cargo::class);
    }

    public function departamento()
    {
        return $this->belongsTo(Departamento::class);
    }

    public function empleado()
    {
        return $this->hasOne(Empleado::class);
    }

    public function estaOcupada(): bool
    {
        return $this->empleado()->where('activo', true)->exists();
    }
}
