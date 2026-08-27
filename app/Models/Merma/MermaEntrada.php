<?php

namespace App\Models\Merma;

use Illuminate\Database\Eloquent\Model;

class MermaEntrada extends Model
{
    protected $connection = 'compras';
    protected $table      = 'merma_entradas';
    public    $timestamps = false;

    protected $fillable = ['inventario_id', 'cerveza_id', 'tamanio', 'cantidad', 'hora_ingreso'];
}
