<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditoriaRecetaItem extends Model
{
    protected $connection = 'compras';
    protected $table      = 'auditoria_receta_items';

    protected $fillable = [
        'auditoria_id', 'receta_id', 'tipo_receta', 'estacion_id',
        'responsable_id', 'responsable_nombre', 'receta_nombre',
        'calificacion', 'clasificacion', 'notas', 'orden',
    ];

    protected $casts = [
        'calificacion' => 'float',
    ];

    public function auditoria()
    {
        return $this->belongsTo(AuditoriaReceta::class, 'auditoria_id');
    }

    public function receta()
    {
        return $this->belongsTo(Receta::class, 'receta_id');
    }

    public function estacion()
    {
        return $this->belongsTo(Estacion::class, 'estacion_id');
    }

    public function criterios()
    {
        return $this->hasMany(AuditoriaRecetaCriterio::class, 'receta_item_id');
    }
}
