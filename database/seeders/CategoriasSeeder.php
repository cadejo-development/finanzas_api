<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Categoria;

class CategoriasSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Categoria::on('pagos')->insert([
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
