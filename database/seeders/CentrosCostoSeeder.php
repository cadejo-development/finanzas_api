<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CentrosCostoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\CentroCosto::insert([
            [
                'codigo' => 'CECO_GUIROLA',
                'nombre' => 'Centro Costo Guírola',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'CECO_STA_TECLA',
                'nombre' => 'Centro Costo Santa Tecla',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
