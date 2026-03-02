<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\System;
use Illuminate\Database\Seeder;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $pagosId = System::where('codigo', 'pagos')->value('id');

        $permisos = [
            ['codigo' => 'crear_solicitudes',       'nombre' => 'Crear solicitudes de pago'],
            ['codigo' => 'ver_solicitudes',          'nombre' => 'Ver solicitudes de pago'],
            ['codigo' => 'aprobar_solicitudes',      'nombre' => 'Aprobar/rechazar solicitudes'],
            ['codigo' => 'gestionar_aprobaciones',   'nombre' => 'Gestionar aprobaciones'],
            ['codigo' => 'auditoria',                'nombre' => 'Ver auditoria'],
        ];

        foreach ($permisos as $data) {
            Permission::updateOrCreate(
                ['codigo' => $data['codigo'], 'system_id' => $pagosId],
                array_merge($data, [
                    'system_id'   => $pagosId,
                    'aud_usuario' => 'seed',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ])
            );
        }
    }
}
