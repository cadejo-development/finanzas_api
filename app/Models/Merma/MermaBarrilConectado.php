<?php

namespace App\Models\Merma;

use Illuminate\Database\Eloquent\Model;

class MermaBarrilConectado extends Model
{
    protected $connection = 'compras';
    protected $table      = 'merma_barriles_conectados';
    public    $timestamps = false;

    protected $fillable = ['item_id', 'tamanio', 'peso_lb'];

    protected $casts = ['peso_lb' => 'decimal:2'];
}
