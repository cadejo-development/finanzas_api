<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class ExpedienteContacto extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'expediente_contactos';

    protected $fillable = [
        'empleado_id', 'tipo', 'etiqueta', 'valor',
        'nombre_contacto', 'es_emergencia', 'orden',
    ];

    protected $casts = [
        'es_emergencia' => 'boolean',
        'orden'         => 'integer',
    ];
}
