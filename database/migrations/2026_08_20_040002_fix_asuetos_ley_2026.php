<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correcciones a los asuetos 2026 sembrados en 040001:
 *
 *  1. Día del Padre: 17-jun (fijo por MTPS), no 21-jun
 *  2. Agosto San Salvador:
 *       - 3-ago → departamental SS  ✓ (correcto)
 *       - 4-ago → NO es asueto de ley; eliminar
 *       - 5-ago → departamental SS  (faltaba, reemplaza al 4)
 *       - 6-ago → NACIONAL (no solo SS); corregir tipo
 */
return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        $geo_ss = DB::connection('pgsql')
            ->table('geo_departamentos')
            ->where('codigo', '06')
            ->value('id');

        // 1. Día del Padre: corregir fecha 21-jun → 17-jun
        DB::connection('pgsql')->table('asuetos')
            ->where('fecha', '2026-06-21')
            ->where('tipo', 'nacional')
            ->update(['fecha' => '2026-06-17', 'updated_at' => now()]);

        // 2. Agosto: eliminar el 4 (no es asueto de ley)
        DB::connection('pgsql')->table('asuetos')
            ->where('fecha', '2026-08-04')
            ->where('tipo', 'departamental')
            ->where('geo_departamento_id', $geo_ss)
            ->delete();

        // 3. Agosto: cambiar el 6 de departamental-SS a nacional
        DB::connection('pgsql')->table('asuetos')
            ->where('fecha', '2026-08-06')
            ->where('tipo', 'departamental')
            ->where('geo_departamento_id', $geo_ss)
            ->update([
                'tipo'                => 'nacional',
                'geo_departamento_id' => null,
                'updated_at'          => now(),
            ]);

        // 4. Agregar 5-ago como departamental San Salvador (si no existe)
        DB::connection('pgsql')->table('asuetos')->updateOrInsert(
            ['fecha' => '2026-08-05', 'tipo' => 'departamental', 'geo_departamento_id' => $geo_ss],
            [
                'nombre'              => 'Fiestas Agostinas',
                'tipo'                => 'departamental',
                'geo_departamento_id' => $geo_ss,
                'activo'              => true,
                'creado_por'          => 'sistema',
                'created_at'          => now(),
                'updated_at'          => now(),
            ]
        );
    }

    public function down(): void
    {
        // No revertible de forma segura; re-ejecutar 040001 si es necesario
    }
};
