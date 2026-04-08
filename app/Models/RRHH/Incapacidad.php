<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class Incapacidad extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'incapacidades';

    protected $fillable = [
        'empleado_id', 'tipo_incapacidad_id', 'tipo_institucion', 'registrado_por_id',
        'fecha_inicio', 'fecha_fin', 'dias',
        'archivo_nombre', 'archivo_ruta',
        'homologada', 'homologada_por_id', 'homologada_en',
        'observaciones', 'aud_usuario',
    ];

    protected $casts = [
        'fecha_inicio'  => 'date',
        'fecha_fin'     => 'date',
        'homologada'    => 'boolean',
        'homologada_en' => 'datetime',
    ];

    public function tipoIncapacidad()
    {
        return $this->belongsTo(TipoIncapacidad::class, 'tipo_incapacidad_id');
    }
}
