<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditoriaItem extends Model
{
    protected $connection = 'compras';
    protected $table      = 'auditoria_items';

    protected $fillable = [
        'auditoria_id', 'criterio_id', 'resultado', 'observaciones', 'foto_url',
    ];

    public function auditoria()
    {
        return $this->belongsTo(AuditoriaReceta::class, 'auditoria_id');
    }

    public function criterio()
    {
        return $this->belongsTo(AuditoriaCriterio::class, 'criterio_id');
    }
}
