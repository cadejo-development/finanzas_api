<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class ExpedienteArchivo extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'expediente_archivos';

    protected $fillable = [
        'empleado_id', 'tipo', 'nombre', 'descripcion',
        'archivo_ruta', 'mime_type', 'tamano_kb', 'subido_por_id',
    ];
}
