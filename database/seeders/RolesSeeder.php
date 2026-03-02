<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\System;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $pagosId = System::where('codigo', 'pagos')->value('id');

        $roles = [
            [
                'nombre'      => 'Administrador',
                'codigo'      => 'admin',
                'system_id'   => $pagosId,
                'aud_usuario' => 'seed',
            ],
            [
                'nombre'      => 'Gerente de Sucursal',
                'codigo'      => 'gerente_sucursal',
                'system_id'   => $pagosId,
                'aud_usuario' => 'seed',
            ],
            [
                'nombre'      => 'Gerente de Logistica',
                'codigo'      => 'gerente_logistica',
                'system_id'   => $pagosId,
                'aud_usuario' => 'seed',
            ],
            [
                'nombre'      => 'Gerente de Mantenimiento',
                'codigo'      => 'gerente_mantenimiento',
                'system_id'   => $pagosId,
                'aud_usuario' => 'seed',
            ],
            [
                'nombre'      => 'Gerencia del Area',
                'codigo'      => 'gerencia_area',
                'system_id'   => $pagosId,
                'aud_usuario' => 'seed',
            ],
            [
                'nombre'      => 'Gerencia Financiera',
                'codigo'      => 'gerencia_financiera',
                'system_id'   => $pagosId,
                'aud_usuario' => 'seed',
            ],
            [
                'nombre'      => 'Gerencia General',
                'codigo'      => 'gerencia_general',
                'system_id'   => $pagosId,
                'aud_usuario' => 'seed',
            ],
        ];

        foreach ($roles as $data) {
            Role::updateOrCreate(
                ['codigo' => $data['codigo'], 'system_id' => $pagosId],
                array_merge($data, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
