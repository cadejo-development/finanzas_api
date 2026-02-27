<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\System;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $sistemas = System::pluck('id', 'codigo');
        Role::insert([
            [
                'nombre' => 'Gerente de Sucursal',
                'codigo' => 'gerente_sucursal',
                'system_id' => $sistemas['pagos'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Jefe del Departamento de Compras',
                'codigo' => 'jefe_compras',
                'system_id' => $sistemas['pagos'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Gerente de Sucursal',
                'codigo' => 'gerente_sucursal',
                'system_id' => $sistemas['compras'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Jefe del Departamento de Compras',
                'codigo' => 'jefe_compras',
                'system_id' => $sistemas['compras'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Administrador',
                'codigo' => 'admin',
                'system_id' => $sistemas['pagos'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Administrador',
                'codigo' => 'admin',
                'system_id' => $sistemas['compras'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
