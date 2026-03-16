<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class TipoPermiso extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'tipos_permiso';

    protected $fillable = [
        'codigo', 'nombre', 'categoria', 'max_dias', 'permite_horas', 'activo', 'aud_usuario',
    ];

    protected $casts = [
        'permite_horas' => 'boolean',
        'activo'        => 'boolean',
        'max_dias'      => 'decimal:1',
    ];

    public function permisos()
    {
        return $this->hasMany(Permiso::class, 'tipo_permiso_id');
    }
}
