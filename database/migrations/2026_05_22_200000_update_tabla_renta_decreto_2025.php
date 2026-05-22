<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Actualiza la tabla de retención ISR quincenal al Decreto Ejecutivo No. 10
 * del 30 de abril de 2025 (Ministerio de Hacienda, El Salvador).
 *
 * Vigente desde la 1ª quincena de mayo 2025.
 * Exención quincenal sube de $169.33 a $275.00 (mensual: de $472 a $550).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Desactivar todos los tramos activos anteriores
        DB::connection('rrhh')
            ->table('planilla_tabla_renta')
            ->where('activo', true)
            ->update(['activo' => false, 'updated_at' => now()]);

        // Insertar nuevos tramos vigentes (Decreto No. 10, 2025)
        $tramos = [
            [
                'desde'           => 0.01,
                'hasta'           => 275.00,
                'cuota_fija'      => 0.00,
                'porcentaje'      => 0.00,
                'sobre_exceso_de' => 0.00,
                'vigente_desde'   => '2025-05-01',
                'activo'          => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            [
                'desde'           => 275.01,
                'hasta'           => 447.62,
                'cuota_fija'      => 8.83,
                'porcentaje'      => 10.00,
                'sobre_exceso_de' => 275.00,
                'vigente_desde'   => '2025-05-01',
                'activo'          => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            [
                'desde'           => 447.63,
                'hasta'           => 1019.05,
                'cuota_fija'      => 30.00,
                'porcentaje'      => 20.00,
                'sobre_exceso_de' => 447.62,
                'vigente_desde'   => '2025-05-01',
                'activo'          => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            [
                'desde'           => 1019.06,
                'hasta'           => null,
                'cuota_fija'      => 144.28,
                'porcentaje'      => 30.00,
                'sobre_exceso_de' => 1019.05,
                'vigente_desde'   => '2025-05-01',
                'activo'          => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
        ];

        DB::connection('rrhh')->table('planilla_tabla_renta')->insert($tramos);
    }

    public function down(): void
    {
        // Revertir: desactivar nueva tabla y restaurar la anterior
        DB::connection('rrhh')
            ->table('planilla_tabla_renta')
            ->where('vigente_desde', '2025-05-01')
            ->update(['activo' => false, 'updated_at' => now()]);

        DB::connection('rrhh')
            ->table('planilla_tabla_renta')
            ->where('vigente_desde', '2024-01-01')
            ->update(['activo' => true, 'updated_at' => now()]);
    }
};
