<?php

namespace Database\Seeders;

use App\Models\PresupuestoUnidad;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PresupuestosUnidadSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PresupuestoUnidad::on('pagos')->insert([
            [
                'centro_costo_id' => 1,
                'anio' => date('Y'),
                'presupuesto_total' => 10000,
                'ejecutado' => 2500,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'centro_costo_id' => 2,
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
