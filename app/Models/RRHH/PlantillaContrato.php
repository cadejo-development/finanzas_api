<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PlantillaContrato extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'plantillas_contrato';

    protected $fillable = [
        'tipo_contrato_id', 'cargo_id', 'cargo_nombre',
        'nombre', 'contenido', 'version', 'activo',
        'aud_usuario',
    ];

    protected $casts = [
        'activo'  => 'boolean',
        'version' => 'integer',
    ];

    public function tipoContrato()
    {
        return $this->belongsTo(TipoContrato::class, 'tipo_contrato_id');
    }
}
