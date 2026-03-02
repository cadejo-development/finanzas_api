<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    protected $connection = 'pgsql';

    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'activo',
        'aud_usuario',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'activo'            => 'boolean',
        ];
    }

    /** Roles del usuario (all systems) */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')
            ->withPivot('aud_usuario')
            ->withTimestamps();
    }

    /** Roles filtrados por sistema */
    public function rolesForSystem(int $systemId)
    {
        return $this->roles()->where('system_id', $systemId)->get();
    }

    /** Verifica si el usuario tiene un rol por código en un sistema dado */
    public function hasRole(string $roleCodigo, int $systemId = null): bool
    {
        $query = $this->roles()->where('roles.codigo', $roleCodigo);
        if ($systemId) {
            $query->where('roles.system_id', $systemId);
        }
        return $query->exists();
    }

    /** Verifica si el usuario tiene un permiso por código */
    public function hasPermission(string $permissionCodigo, int $systemId = null): bool
    {
        foreach ($this->roles as $role) {
            if ($systemId && $role->system_id !== $systemId) {
                continue;
            }
            if ($role->permissions()->where('permissions.codigo', $permissionCodigo)->exists()) {
                return true;
            }
        }
        return false;
    }
}
