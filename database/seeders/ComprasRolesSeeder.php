<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RoleUser;
use App\Models\System;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Crea roles para el sistema 'compras' y asigna usuarios existentes.
 */
class ComprasRolesSeeder extends Seeder
{
    public function run(): void
    {
        $comprasId = System::where('codigo', 'compras')->value('id');

        if (! $comprasId) {
            $this->command->warn('Sistema "compras" no encontrado. Ejecuta SystemsSeeder primero.');
            return;
        }

        // ── Roles ────────────────────────────────────────────────────────────
        $rolesData = [
            [
                'nombre'    => 'Administrador Compras',
                'codigo'    => 'admin_compras',
                'system_id' => $comprasId,
            ],
            [
                'nombre'    => 'Gerente de Sucursal',
                'codigo'    => 'gerente_sucursal',
                'system_id' => $comprasId,
            ],
            [
                'nombre'    => 'Encargado de Compras',
                'codigo'    => 'encargado_compras',
                'system_id' => $comprasId,
            ],
        ];

        foreach ($rolesData as $data) {
            Role::updateOrCreate(
                ['codigo' => $data['codigo'], 'system_id' => $comprasId],
                array_merge($data, [
                    'aud_usuario' => 'seed',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ])
            );
        }

        // ── Usuario Encargado de Compras ─────────────────────────────────────
        User::updateOrCreate(
            ['email' => 'compras@demo.com'],
            [
                'name'        => 'Encargado Compras',
                'password'    => Hash::make('compras123'),
                'activo'      => true,
                'aud_usuario' => 'seed',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]
        );

        // ── Asignaciones de usuarios ─────────────────────────────────────────
        $rolMap  = Role::where('system_id', $comprasId)->pluck('id', 'codigo');
        $userMap = User::pluck('id', 'email');

        $asignaciones = [
            ['email' => 'admin@demo.com',   'rol' => 'admin_compras'],
            ['email' => 'gerente@demo.com', 'rol' => 'gerente_sucursal'],
            ['email' => 'compras@demo.com', 'rol' => 'encargado_compras'],
        ];

        foreach ($asignaciones as $a) {
            $userId = $userMap[$a['email']] ?? null;
            $roleId = $rolMap[$a['rol']] ?? null;

            if ($userId && $roleId) {
                RoleUser::updateOrCreate(
                    ['user_id' => $userId, 'role_id' => $roleId],
                    ['aud_usuario' => 'seed', 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        $this->command->info('Roles y asignaciones de compras creados correctamente.');
    }
}
