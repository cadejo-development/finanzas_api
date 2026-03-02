<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\System;
use Illuminate\Database\Seeder;

class PermissionRoleSeeder extends Seeder
{
    public function run(): void
    {
        $pagosId = System::where('codigo', 'pagos')->value('id');

        $rolMap = Role::where('system_id', $pagosId)->pluck('id', 'codigo');
        $permMap = Permission::where('system_id', $pagosId)->pluck('id', 'codigo');

        // [rol_codigo => [permiso_codigo, ...]]
        $mappings = [
            'admin'                  => ['crear_solicitudes', 'ver_solicitudes', 'aprobar_solicitudes', 'gestionar_aprobaciones', 'auditoria'],
            'gerente_sucursal'       => ['crear_solicitudes', 'ver_solicitudes'],
            'gerente_logistica'      => ['ver_solicitudes', 'aprobar_solicitudes'],
            'gerente_mantenimiento'  => ['ver_solicitudes', 'aprobar_solicitudes'],
            'gerencia_area'          => ['ver_solicitudes', 'aprobar_solicitudes'],
            'gerencia_financiera'    => ['ver_solicitudes', 'aprobar_solicitudes'],
            'gerencia_general'       => ['ver_solicitudes', 'aprobar_solicitudes'],
        ];

        foreach ($mappings as $rolCodigo => $permisos) {
            $roleId = $rolMap[$rolCodigo] ?? null;
            if (! $roleId) continue;

            foreach ($permisos as $permCodigo) {
                $permId = $permMap[$permCodigo] ?? null;
                if (! $permId) continue;

                PermissionRole::updateOrCreate(
                    ['role_id' => $roleId, 'permission_id' => $permId],
                    ['aud_usuario' => 'seed', 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }
}
