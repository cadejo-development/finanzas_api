<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class Desvinculacion extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'desvinculaciones';

    protected $fillable = [
        'empleado_id', 'procesado_por_id', 'motivo_id',
        'tipo', 'fecha_efectiva', 'observaciones',
        'empleado_nombre', 'cargo_nombre', 'sucursal_nombre',
        'archivo_nombre', 'archivo_ruta',
        'aud_usuario',
    ];

    protected $casts = ['fecha_efectiva' => 'date'];

    public function motivo()
    {
        return $this->belongsTo(MotivoDesvinculacion::class, 'motivo_id');
    }
}
