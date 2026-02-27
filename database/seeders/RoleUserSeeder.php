<?php

namespace Database\Seeders;

use App\Models\RoleUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleUserSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::pluck('id', 'codigo');
        $users = User::pluck('id', 'email');
        RoleUser::insert([
            [
                'user_id' => $users['admin@demo.com'] ?? null,
                'role_id' => $roles['admin'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $users['gerente@demo.com'] ?? null,
                'role_id' => $roles['gerente_sucursal'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $users['jefe@demo.com'] ?? null,
                'role_id' => $roles['jefe_compras'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
