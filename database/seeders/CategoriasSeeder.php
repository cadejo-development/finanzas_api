<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Categoria;
use Illuminate\Support\Facades\DB;

class CategoriasSeeder extends Seeder
{
    public function run(): void
    {
        // Solo ejecutar en la base central
        if (Categoria::resolveConnection()->getName() !== 'pgsql') {
            return;
        }
        // Truncar tabla (PostgreSQL)
        DB::statement('TRUNCATE TABLE categorias RESTART IDENTITY CASCADE');

        // Insertar datos
        Categoria::insert([
            [
                'key' => 'general',
                'nombre' => 'GENERAL',
                'orden' => 1,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'cp',
                'nombre' => 'CP TERMINADO',
                'orden' => 2,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'empaque',
                'nombre' => 'EMPAQUE',
                'orden' => 3,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'promo',
                'nombre' => 'PROMOCIONALES',
                'orden' => 4,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'extras',
                'nombre' => 'EXTRAS',
                'orden' => 5,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}