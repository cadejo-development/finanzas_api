<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BriloConfigSucursal extends Model
{
    protected $connection = 'compras';
    protected $table      = 'brilo_config_sucursal';
    protected $primaryKey = 'sucursal_id';
    public    $incrementing = false;

    protected $fillable = ['sucursal_id', 'bodega_codigo'];
}
