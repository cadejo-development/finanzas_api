<?php

namespace Database\Seeders;

use App\Models\PermissionRole;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionRoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::pluck('id', 'codigo');
        $permisos = Permission::pluck('id', 'codigo');
        PermissionRole::insert([
            [
                'permission_id' => $permisos['gestionar_aprobaciones'] ?? null,
                'role_id' => $roles['gerente_sucursal'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'permission_id' => $permisos['gestionar_aprobaciones'] ?? null,
                'role_id' => $roles['jefe_compras'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'permission_id' => $permisos['auditoria'] ?? null,
                'role_id' => $roles['admin'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
