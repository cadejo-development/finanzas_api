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
            DB::connection('rrhh')->table('planilla_config')->updateOrInsert(
                ['clave' => $config['clave']],
                array_merge($config, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        // ── Tabla de Renta Quincenal — Decreto Ejecutivo No. 10, 30-04-2025 ────
        // Vigente desde 1ª quincena de mayo 2025 (Art. 33 Ley ISR El Salvador)
        // Exención mensual sube de $472 a $550 → quincenal exento hasta $275
        DB::connection('rrhh')->table('planilla_tabla_renta')
            ->where('activo', true)
            ->update(['activo' => false]);

        $tramos = [
            [
                'desde'           => 0.01,
                'hasta'           => 275.00,
                'cuota_fija'      => 0.00,
                'porcentaje'      => 0.00,
                'sobre_exceso_de' => 0.00,
            ],
            [
                'desde'           => 275.01,
                'hasta'           => 447.62,
                'cuota_fija'      => 8.83,
                'porcentaje'      => 10.00,
                'sobre_exceso_de' => 275.00,
            ],
            [
                'desde'           => 447.63,
                'hasta'           => 1019.05,
                'cuota_fija'      => 30.00,
                'porcentaje'      => 20.00,
                'sobre_exceso_de' => 447.62,
            ],
            [
                'desde'           => 1019.06,
                'hasta'           => null,
                'cuota_fija'      => 144.28,
                'porcentaje'      => 30.00,
                'sobre_exceso_de' => 1019.05,
            ],
        ];

        foreach ($tramos as $tramo) {
            DB::connection('rrhh')->table('planilla_tabla_renta')->insert(array_merge($tramo, [
                'vigente_desde' => '2025-05-01',
                'activo'        => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]));
        }

        $this->command->info('PlanillaSeeder: configuración y tabla de renta insertadas (Decreto No. 10, 2025).');
    }
}
