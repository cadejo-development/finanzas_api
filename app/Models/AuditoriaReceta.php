<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\AuditoriaRecetaItem;

class AuditoriaReceta extends Model
{
    protected $connection = 'compras';
    protected $table      = 'auditorias_receta';

    protected $fillable = [
        'tipo', 'fecha', 'hora', 'sucursal_id', 'estacion_id', 'receta_id',
        'tipo_receta', 'es_multi_receta', 'responsable_id', 'responsable_nombre',
        'evaluador_id', 'evaluador_nombre', 'notas', 'estado',
        'calificacion', 'clasificacion', 'observaciones_generales', 'acciones_correctivas',
        'respondido_por_id', 'respondido_por_nombre', 'respondido_at',
        'comentario_gerente', 'aud_usuario',
        'submitted_at', 'gerente_deadline_at', 'gerente_respondio',
        'kristian_notificado_at', 'kristian_deadline_at',
    ];

    protected $casts = [
        'fecha'                  => 'date',
        'es_multi_receta'        => 'boolean',
        'respondido_at'          => 'datetime',
        'submitted_at'           => 'datetime',
        'gerente_deadline_at'    => 'datetime',
        'gerente_respondio'      => 'boolean',
        'kristian_notificado_at' => 'datetime',
        'kristian_deadline_at'   => 'datetime',
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

    public function recetaItems()
    {
        return $this->hasMany(AuditoriaRecetaItem::class, 'auditoria_id')->orderBy('orden');
    }
}
