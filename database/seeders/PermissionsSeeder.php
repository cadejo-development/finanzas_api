<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\System;
use Illuminate\Database\Seeder;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $sistemas = System::pluck('id', 'codigo');
        Permission::insert([
            [
                'nombre' => 'Gestionar aprobaciones',
                'codigo' => 'gestionar_aprobaciones',
                'system_id' => $sistemas['pagos'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Ver auditoría',
                'codigo' => 'auditoria',
                'system_id' => $sistemas['pagos'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Gestionar aprobaciones',
                'codigo' => 'gestionar_aprobaciones',
                'system_id' => $sistemas['compras'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Ver auditoría',
                'codigo' => 'auditoria',
                'system_id' => $sistemas['compras'] ?? null,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
