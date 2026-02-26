<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PresupuestoUnidad;

class PresupuestosUnidadSeeder extends Seeder
{
    public function run(): void
    {
        // Truncar tabla (PostgreSQL)
        PresupuestoUnidad::truncate();

        // Insertar datos
        PresupuestoUnidad::insert([
            [
                'centro_costo_codigo' => 'CECO_GUIROLA',
                'anio' => date('Y'),
                'presupuesto_total' => 10000,
                'ejecutado' => 2500,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'centro_costo_codigo' => 'CECO_STA_TECLA',
                'anio' => date('Y'),
                'presupuesto_total' => 8000,
                'ejecutado' => 1200,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}