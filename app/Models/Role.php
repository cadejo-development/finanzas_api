<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'roles';
    protected $fillable = [
        'nombre', 'codigo', 'system_id', 'aud_usuario'
    ];

    public function system()
    {
        return $this->belongsTo(System::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id')
            ->withPivot('aud_usuario')
            ->withTimestamps();
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'permission_role', 'role_id', 'permission_id')
            ->withPivot('aud_usuario')
            ->withTimestamps();
    }
}
