<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1) Agrega columna `activa` a sucursales (default true).
 * 2) Marca como inactivas las 4 sucursales cerradas:
 *    - Coatepeque (SUC-CO)
 *    - Puerta del Diablo (SUC-PD)
 *    - San Miguel (SUC-SM)
 *    - Santa Rosa (SUC-SR)
 * 3) Limpia referencias FK en users y centros_costo.
 */
return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    private array $codigosCerradas = ['SUC-CO', 'SUC-PD', 'SUC-SM', 'SUC-SR'];

    public function up(): void
    {
        // 1. Agregar columna activa si no existe
        if (!Schema::connection('pgsql')->hasColumn('sucursales', 'activa')) {
            Schema::connection('pgsql')->table('sucursales', function (Blueprint $table) {
                $table->boolean('activa')->default(true)->after('tipo');
            });
        }

        // 2. Obtener IDs de las sucursales cerradas
        $ids = DB::connection('pgsql')
            ->table('sucursales')
            ->whereIn('codigo', $this->codigosCerradas)
            ->pluck('id')
            ->toArray();

        if (empty($ids)) {
            return; // Ya no existen
        }

        // 3. Marcar como inactivas
        DB::connection('pgsql')
            ->table('sucursales')
            ->whereIn('id', $ids)
            ->update(['activa' => false]);

        // 4. Desvincular usuarios asignados a esas sucursales
        DB::connection('pgsql')
            ->table('users')
            ->whereIn('sucursal_id', $ids)
            ->update(['sucursal_id' => null]);

        // 5. Limpiar tabla user_sucursales (pivot multi-sucursal)
        if (Schema::connection('pgsql')->hasTable('user_sucursales')) {
            DB::connection('pgsql')
                ->table('user_sucursales')
                ->whereIn('sucursal_id', $ids)
                ->delete();
        }

        // 6. Desasociar centros de costo de esas sucursales
        if (Schema::connection('pgsql')->hasColumn('centros_costo', 'sucursal_id')) {
            DB::connection('pgsql')
                ->table('centros_costo')
                ->whereIn('sucursal_id', $ids)
                ->update(['sucursal_id' => null]);
        }
    }

    public function down(): void
    {
        DB::connection('pgsql')
            ->table('sucursales')
            ->whereIn('codigo', $this->codigosCerradas)
            ->update(['activa' => true]);

        Schema::connection('pgsql')->table('sucursales', function (Blueprint $table) {
            $table->dropColumn('activa');
        });
    }
};
