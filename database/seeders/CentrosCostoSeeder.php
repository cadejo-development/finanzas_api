<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CentroCosto;

class CentrosCostoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        CentroCosto::insert([
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
