<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Asigna centros de costo a usuarios.
 * gerente@demo.com → RESTAURANTE CASA GUIROLA (CECO-14, CECO-14-01, CECO-14-02)
 */
class UserCentrosCostoSeeder extends Seeder
{
    public function run(): void
    {
        DB::connection('pgsql')->table('user_centros_costo')->truncate();

        $gerenteId = DB::connection('pgsql')
            ->table('users')
            ->where('email', 'gerente@demo.com')
            ->value('id');

        if (!$gerenteId) {
            $this->command->warn('Usuario gerente@demo.com no encontrado. Saltando UserCentrosCostoSeeder.');
            return;
        }

        $asignaciones = [
            'CECO-14',    // RESTAURANTE CASA GUIROLA (padre/agrupador)
            'CECO-14-01', // RESTAURANTE CASA GUIROLA (operativo)
            'CECO-14-02', // EVENTOS CASA GUIROLA (operativo)
        ];

        $now = now();
        foreach ($asignaciones as $codigo) {
            DB::connection('pgsql')->table('user_centros_costo')->updateOrInsert(
                ['user_id' => $gerenteId, 'centro_costo_codigo' => $codigo],
                ['aud_usuario' => 'seed', 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }
}
