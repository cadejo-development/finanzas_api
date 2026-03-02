<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CentrosCostoSeeder extends Seeder
{
    public function run(): void
    {
        // Truncar tabla (PostgreSQL) — usar conexión explícita
        DB::connection('pgsql')->statement('TRUNCATE TABLE centros_costo RESTART IDENTITY CASCADE');

        $now = now();
        $rows = [
            ['codigo' => 'CC-ADM',  'nombre' => 'Administración Central'],
            ['codigo' => 'CC-OPE',  'nombre' => 'Operaciones'],
            ['codigo' => 'CC-LOG',  'nombre' => 'Logística'],
            ['codigo' => 'CC-MKT',  'nombre' => 'Marketing y Ventas'],
            ['codigo' => 'CC-PROD', 'nombre' => 'Producción / Planta'],
            ['codigo' => 'CC-MAN',  'nombre' => 'Mantenimiento'],
            ['codigo' => 'CC-FIN',  'nombre' => 'Finanzas y Contabilidad'],
            ['codigo' => 'CC-RH',   'nombre' => 'Recursos Humanos'],
            ['codigo' => 'CC-TI',   'nombre' => 'Tecnología e Informática'],
            ['codigo' => 'CC-CAL',  'nombre' => 'Control de Calidad'],
        ];

        foreach ($rows as $row) {
            DB::connection('pgsql')->table('centros_costo')->updateOrInsert(
                ['codigo' => $row['codigo']],
                array_merge($row, ['aud_usuario' => 'seed', 'created_at' => $now, 'updated_at' => $now])
            );
        }
    }
}