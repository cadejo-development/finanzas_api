<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategoriasSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\Categoria::insert([
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
