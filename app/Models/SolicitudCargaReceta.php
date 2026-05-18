<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudCargaReceta extends Model
{
    protected $connection = 'compras';
    protected $table      = 'solicitudes_carga_receta';

    protected $fillable = [
        'fecha_requerida',
        'nota',
        'estado',
        'solicitado_por',
        'solicitado_por_nombre',
        'receta_ids',
        'receta_nombres',
        'total_recetas',
        'aud_usuario',
    ];

    protected $casts = [
        'fecha_requerida' => 'date',
        'receta_ids'      => 'array',
        'receta_nombres'  => 'array',
        'total_recetas'   => 'integer',
    ];
}
