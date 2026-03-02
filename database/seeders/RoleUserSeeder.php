<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RoleUser;
use App\Models\System;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleUserSeeder extends Seeder
{
    public function run(): void
    {
        $pagosId = System::where('codigo', 'pagos')->value('id');

        $rolMap = Role::where('system_id', $pagosId)->pluck('id', 'codigo');
        $userMap = User::pluck('id', 'email');

        $asignaciones = [
            ['email' => 'admin@demo.com',      'rol' => 'admin'],
            ['email' => 'gerente@demo.com',    'rol' => 'gerente_sucursal'],
            ['email' => 'nelson@demo.com',     'rol' => 'gerente_logistica'],
            ['email' => 'fabio@demo.com',      'rol' => 'gerente_mantenimiento'],
            ['email' => 'garea@demo.com',      'rol' => 'gerencia_area'],
            ['email' => 'juanjose@demo.com',   'rol' => 'gerencia_financiera'],
            ['email' => 'david@demo.com',      'rol' => 'gerencia_general'],
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
    }
}
