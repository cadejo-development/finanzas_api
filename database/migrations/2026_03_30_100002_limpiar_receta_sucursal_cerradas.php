<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Elimina los registros de receta_sucursal (compras DB) que pertenezcan
 * a las 4 sucursales cerradas: Coatepeque, Puerta del Diablo, San Miguel, Santa Rosa.
 *
 * Los IDs se obtienen de pgsql en tiempo de ejecución para no hardcodearlos.
 */
return new class extends Migration
{
    public function getConnection(): string { return 'compras'; }

    private array $codigosCerradas = ['SUC-CO', 'SUC-PD', 'SUC-SM', 'SUC-SR'];

    public function up(): void
    {
        // Obtener IDs desde pgsql
        $ids = DB::connection('pgsql')
            ->table('sucursales')
            ->whereIn('codigo', $this->codigosCerradas)
            ->pluck('id')
            ->toArray();

        if (empty($ids)) {
            return;
        }

        $deleted = DB::connection('compras')
            ->table('receta_sucursal')
            ->whereIn('sucursal_id', $ids)
            ->delete();

        \Log::info("limpiar_receta_sucursal_cerradas: eliminados {$deleted} registros de receta_sucursal para sucursales " . implode(',', $this->codigosCerradas));
    }

    public function down(): void
    {
        // No restauramos: los datos de sucursales cerradas no se deben recuperar.
    }
};
