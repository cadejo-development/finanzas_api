<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditoriaFoto extends Model
{
    public    $timestamps = false;
    protected $connection = 'compras';
    protected $table      = 'auditoria_fotos';

    protected $fillable = [
        'auditoria_id', 'url', 'descripcion', 'orden',
    ];
}
