<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditoriaCriterio extends Model
{
    protected $connection = 'compras';
    protected $table      = 'auditoria_criterios';

    protected $fillable = ['categoria', 'categoria_orden', 'nombre', 'peso', 'activo', 'orden'];

    public function items()
    {
        return $this->hasMany(AuditoriaItem::class, 'criterio_id');
    }
}
