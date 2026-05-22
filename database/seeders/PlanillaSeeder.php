<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanillaSeeder extends Seeder
{
    public function run(): void
    {
        // ── Configuración ──────────────────────────────────────────────────────
        $configs = [
            [
                'clave'       => 'afp_empleado_porcentaje',
                'valor'       => 7.2500,
                'descripcion' => 'Porcentaje AFP descontado al empleado (7.25%)',
            ],
            [
                'clave'       => 'afp_patronal_porcentaje',
                'valor'       => 8.7500,
                'descripcion' => 'Porcentaje AFP aporte patronal (8.75%)',
            ],
            [
                'clave'       => 'afp_tope_quincenal',
                'valor'       => 3814.5900,
                'descripcion' => 'Tope quincenal para cálculo AFP ($3,814.59 = $7,629.18 / 2)',
            ],
            [
                'clave'       => 'isss_empleado_porcentaje',
                'valor'       => 3.0000,
                'descripcion' => 'Porcentaje ISSS descontado al empleado (3.0%)',
            ],
            [
                'clave'       => 'isss_patronal_porcentaje',
                'valor'       => 7.5000,
                'descripcion' => 'Porcentaje ISSS aporte patronal (7.5%)',
            ],
            [
                'clave'       => 'isss_tope_quincenal',
                'valor'       => 500.0000,
                'descripcion' => 'Tope quincenal para cálculo ISSS ($500 = $1,000 / 2)',
            ],
            [
                'clave'       => 'insaforp_patronal_porcentaje',
                'valor'       => 1.0000,
                'descripcion' => 'Porcentaje INSAFORP aporte patronal (1.0%, sin tope)',
            ],
        ];

        foreach ($configs as $config) {
            DB::connection('pgsql')->table('planilla_config')->updateOrInsert(
                ['clave' => $config['clave']],
                array_merge($config, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        // ── Tabla de Renta Quincenal 2024/2025 ────────────────────────────────
        // Primero desactivar cualquier tabla activa previa
        DB::connection('pgsql')->table('planilla_tabla_renta')
            ->where('activo', true)
            ->update(['activo' => false]);

        $tramos = [
            [
                'desde'           => 0.01,
                'hasta'           => 169.33,
                'cuota_fija'      => 0.00,
                'porcentaje'      => 0.00,
                'sobre_exceso_de' => 0.00,
            ],
            [
                'desde'           => 169.34,
                'hasta'           => 380.95,
                'cuota_fija'      => 0.00,
                'porcentaje'      => 10.00,
                'sobre_exceso_de' => 169.33,
            ],
            [
                'desde'           => 380.96,
                'hasta'           => 952.38,
                'cuota_fija'      => 21.16,
                'porcentaje'      => 20.00,
                'sobre_exceso_de' => 380.95,
            ],
            [
                'desde'           => 952.39,
                'hasta'           => null,
                'cuota_fija'      => 135.45,
                'porcentaje'      => 30.00,
                'sobre_exceso_de' => 952.38,
            ],
        ];

        foreach ($tramos as $tramo) {
            DB::connection('pgsql')->table('planilla_tabla_renta')->insert(array_merge($tramo, [
                'vigente_desde' => '2024-01-01',
                'activo'        => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]));
        }

        $this->command->info('PlanillaSeeder: configuración y tabla de renta insertadas.');
    }
}
