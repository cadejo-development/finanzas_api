<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill tipo_sucursal_id en sucursales que lo tienen en NULL.
 *
 * Esto puede ocurrir si la migración 2026_03_10 que hacía el UPDATE
 * no completó todos los registros, o si se agregaron sucursales después
 * sin asignar tipo_sucursal_id. Sin este valor, el reporte quincenal
 * no puede determinar si la sucursal es operativa (restaurante) y
 * calcula dias_propinas = 0 incorrectamente.
 */
return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    // Sucursales operativas (restaurantes) según su código
    private array $operativas = [
        'SUC-ZR', 'SUC-SR', 'SUC-LL', 'SUC-AE1', 'SUC-AE2',
        'SUC-SM', 'SUC-PV', 'SUC-SE', 'SUC-HZ',  'SUC-OP',
        'SUC-GU', 'SUC-CO', 'SUC-PD',
    ];

    public function up(): void
    {
        // Verificar que tipos_sucursal existe con los registros esperados
        $tiposExisten = DB::connection('pgsql')
            ->table('tipos_sucursal')
            ->whereIn('codigo', ['operativa', 'area_corporativa'])
            ->count();

        if ($tiposExisten < 2) {
            // Si no existen los tipos, crearlos
            $now = now();
            DB::connection('pgsql')->table('tipos_sucursal')->insertOrIgnore([
                ['codigo' => 'operativa',        'nombre' => 'Operativa',        'created_at' => $now, 'updated_at' => $now],
                ['codigo' => 'area_corporativa', 'nombre' => 'Área Corporativa', 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        $idOperativa      = DB::connection('pgsql')->table('tipos_sucursal')->where('codigo', 'operativa')->value('id');
        $idAreaCorporativa = DB::connection('pgsql')->table('tipos_sucursal')->where('codigo', 'area_corporativa')->value('id');

        if (!$idOperativa || !$idAreaCorporativa) return;

        // Backfill sucursales operativas con tipo_sucursal_id NULL
        DB::connection('pgsql')
            ->table('sucursales')
            ->whereIn('codigo', $this->operativas)
            ->whereNull('tipo_sucursal_id')
            ->update(['tipo_sucursal_id' => $idOperativa]);

        // Backfill sucursales corporativas con tipo_sucursal_id NULL
        DB::connection('pgsql')
            ->table('sucursales')
            ->whereNotIn('codigo', $this->operativas)
            ->whereNull('tipo_sucursal_id')
            ->update(['tipo_sucursal_id' => $idAreaCorporativa]);
    }

    public function down(): void
    {
        // No hay down seguro: revertir borraría datos que quizás ya eran correctos
    }
};
