<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $table = 'permissions';
    protected $fillable = [
        'nombre', 'codigo', 'system_id', 'aud_usuario'
    ];

    public function system() {
        return $this->belongsTo(System::class);
    }
}
