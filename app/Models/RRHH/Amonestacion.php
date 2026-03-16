<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class Amonestacion extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'amonestaciones';

    protected $fillable = [
        'empleado_id', 'jefe_id', 'tipo_falta_id',
        'fecha_amonestacion', 'descripcion', 'accion_tomada',
        'aplica_suspension', 'aud_usuario',
    ];

    protected $casts = [
        'fecha_amonestacion' => 'date',
        'aplica_suspension'  => 'boolean',
    ];

    public function tipoFalta()
    {
        return $this->belongsTo(TipoFalta::class, 'tipo_falta_id');
    }

    public function diasSuspension()
    {
        return $this->hasMany(DiaSuspension::class, 'amonestacion_id')->orderBy('fecha');
    }
}
