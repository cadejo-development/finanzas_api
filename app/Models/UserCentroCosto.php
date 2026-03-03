<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCentroCosto extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'user_centros_costo';
    protected $fillable = ['user_id', 'centro_costo_codigo', 'aud_usuario'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function centroCosto()
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_codigo', 'codigo');
    }
}
