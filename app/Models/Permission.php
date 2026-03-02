<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'permissions';
    protected $fillable = [
        'nombre', 'codigo', 'system_id', 'aud_usuario'
    ];

    public function system()
    {
        return $this->belongsTo(System::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'permission_role', 'permission_id', 'role_id')
            ->withPivot('aud_usuario')
            ->withTimestamps();
    }
}
