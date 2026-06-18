<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class TipoContrato extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'tipos_contrato';

    protected $fillable = [
        'codigo', 'nombre', 'descripcion',
        'duracion_dias', 'es_periodo_prueba', 'activo',
        'aud_usuario',
    ];

    protected $casts = [
        'es_periodo_prueba' => 'boolean',
        'activo'            => 'boolean',
        'duracion_dias'     => 'integer',
    ];

    public function plantillas()
    {
        return $this->hasMany(PlantillaContrato::class, 'tipo_contrato_id');
    }

    public function contratos()
    {
        return $this->hasMany(ContratoEmpleado::class, 'tipo_contrato_id');
    }
}
