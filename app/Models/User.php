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
        'sucursal_id',
        'aud_usuario',
        'force_password_change',
        'reset_code',
        'reset_code_expires_at',
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

    /** Sucursales adicionales asignadas explícitamente (multi-sucursal) */
    public function sucursalesAdicionales()
    {
        return $this->belongsToMany(Sucursal::class, 'user_sucursales', 'user_id', 'sucursal_id');
    }

    /**
     * Devuelve todos los IDs de sucursal que el usuario puede gestionar
     * (la sucursal principal + las adicionales del pivot).
     */
    public function todasSucursalesIds(): array
    {
        $ids = $this->sucursal_id ? [$this->sucursal_id] : [];
        $adicionales = $this->sucursalesAdicionales()->pluck('sucursales.id')->toArray();
        return array_values(array_unique(array_merge($ids, $adicionales)));
    }

    /** Permisos directos asignados al usuario (sin depender del rol) */
    public function directPermissions()
    {
        return $this->belongsToMany(Permission::class, 'permission_user', 'user_id', 'permission_id')
            ->withPivot('aud_usuario')
            ->withTimestamps();
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

    /** Verifica si el usuario tiene un permiso por código (vía rol o asignación directa) */
    public function hasPermission(string $permissionCodigo, int $systemId = null): bool
    {
        // Permisos directos por usuario
        $directQuery = $this->directPermissions()->where('permissions.codigo', $permissionCodigo);
        if ($systemId) {
            $directQuery->where('permissions.system_id', $systemId);
        }
        if ($directQuery->exists()) {
            return true;
        }

        // Permisos heredados del rol
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

    /**
     * Verifica si el usuario es auditor de inventario mensual.
     * Chequea tanto hasPermission (sistema estándar de roles) como
     * inventario_sucursal_roles (asignación por sucursal específica).
     * Si se pasa $sucursalId, valida que sea auditor de ESA sucursal.
     */
    public function esAuditorInventario(?int $sucursalId = null): bool
    {
        if ($this->hasPermission('puede_auditar_conteo')) {
            return true;
        }

        $query = \Illuminate\Support\Facades\DB::table('inventario_sucursal_roles')
            ->where('user_id', $this->id)
            ->where('rol', 'auditor_inv')
            ->where('activo', true);

        if ($sucursalId) {
            $query->where('sucursal_id', $sucursalId);
        }

        return $query->exists();
    }
}
