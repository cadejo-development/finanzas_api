<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class TipoPermiso extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'tipos_permiso';

    protected $fillable = [
        'codigo', 'nombre', 'categoria',
        'max_dias', 'duracion_max_dias',
        'permite_horas', 'solo_dias_completos',
        'requiere_documento', 'anticipacion_min_dias', 'dentro_de_dias_evento',
        'activo', 'aud_usuario',
    ];

    protected $casts = [
        'permite_horas'         => 'boolean',
        'solo_dias_completos'   => 'boolean',
        'requiere_documento'    => 'boolean',
        'activo'                => 'boolean',
        'max_dias'              => 'decimal:1',
        'duracion_max_dias'     => 'decimal:1',
    ];

    public function permisos()
    {
        return $this->hasMany(Permiso::class, 'tipo_permiso_id');
    }
}
