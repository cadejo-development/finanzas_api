<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'roles';
    protected $fillable = [
        'nombre', 'codigo', 'system_id', 'aud_usuario'
    ];

    public function system() {
        return $this->belongsTo(System::class);
    }
}
