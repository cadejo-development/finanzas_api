<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditoriaReceta extends Model
{
    protected $connection = 'compras';
    protected $table      = 'auditorias_receta';

    protected $fillable = [
        'fecha', 'hora', 'sucursal_id', 'estacion_id', 'receta_id',
        'tipo_receta', 'responsable_id', 'responsable_nombre',
        'evaluador_id', 'evaluador_nombre', 'notas', 'estado', 'calificacion', 'aud_usuario',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function estacion()
    {
        return $this->belongsTo(Estacion::class, 'estacion_id');
    }

    public function receta()
    {
        return $this->belongsTo(Receta::class, 'receta_id');
    }

    public function fotos()
    {
        return $this->hasMany(AuditoriaFoto::class, 'auditoria_id')->orderBy('orden');
    }
}
