<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Presupuestos 2026 para centros de costo de RESTAURANTE CASA GUIROLA
 */
class PresupuestosUnidadSeeder extends Seeder
{
    public function run(): void
    {
        DB::connection('pagos')->table('presupuestos_unidad')->truncate();

        $anio = (int) date('Y');
        $now  = now();

        $rows = [
            // Agrupador de Casa Guirola — presupuesto total de la sucursal
            [
                'centro_costo_codigo' => 'CECO-14',
                'anio'                => $anio,
                'presupuesto_total'   => 50000.00,
                'ejecutado'           => 12500.00,
            ],
            // Operaciones del restaurante
            [
                'centro_costo_codigo' => 'CECO-14-01',
                'anio'                => $anio,
                'presupuesto_total'   => 35000.00,
                'ejecutado'           => 8750.00,
            ],
            // Eventos Casa Guirola
            [
                'centro_costo_codigo' => 'CECO-14-02',
                'anio'                => $anio,
                'presupuesto_total'   => 15000.00,
                'ejecutado'           => 850.00,
            ],
        ];

        foreach ($rows as $row) {
            DB::connection('pagos')->table('presupuestos_unidad')->insert(
                array_merge($row, ['aud_usuario' => 'seed', 'created_at' => $now, 'updated_at' => $now])
            );
        }
    }
}
