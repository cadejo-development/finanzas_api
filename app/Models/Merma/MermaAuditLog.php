<?php

namespace App\Models\Merma;

use Illuminate\Database\Eloquent\Model;

class MermaAuditLog extends Model
{
    protected $connection = 'compras';
    protected $table      = 'merma_audit_log';
    public    $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'inventario_id', 'usuario_id', 'usuario_nombre',
        'sucursal_id', 'sucursal_nombre',
        'evento', 'valor_original', 'valor_nuevo', 'comentario',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
