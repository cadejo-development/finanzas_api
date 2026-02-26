<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CentroCosto;
use Illuminate\Support\Facades\DB;

class CentrosCostoSeeder extends Seeder
{
    public function run(): void
    {
        // Truncar tabla (PostgreSQL)
        DB::statement('TRUNCATE TABLE centros_costo RESTART IDENTITY CASCADE');

        // Insertar datos
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