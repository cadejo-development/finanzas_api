<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sucursal;

class SucursalesSeeder extends Seeder
{
    public function run(): void
    {
        // Solo ejecutar en la base central
        if (Sucursal::resolveConnection()->getName() !== 'pgsql') {
            return;
        }
        // Truncar usando el modelo (ya trae la conexión)
        Sucursal::truncate();

        // Insertar registros
        Sucursal::insert([
            [
                'codigo' => 'S01',
                'nombre' => 'Guírola',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'S02',
                'nombre' => 'Santa Tecla',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}