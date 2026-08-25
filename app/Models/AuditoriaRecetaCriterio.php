<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditoriaRecetaCriterio extends Model
{
    protected $connection = 'compras';
    protected $table      = 'auditoria_receta_criterios';

    protected $fillable = [
        'auditoria_id', 'receta_item_id', 'criterio_id',
        'resultado', 'observaciones', 'foto_url',
    ];

    public function criterio()
    {
        return $this->belongsTo(AuditoriaCriterio::class, 'criterio_id');
    }
}
