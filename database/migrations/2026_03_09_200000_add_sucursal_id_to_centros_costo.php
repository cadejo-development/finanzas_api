<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega sucursal_id a centros_costo y mapea cada agrupador (padre) a su sucursal.
 * Los hijos heredan el sucursal_id del padre vía UPDATE.
 */
return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    /** Mapa CECO-agrupador → codigo de sucursal */
    private array $mapaAgrupadores = [
        'CECO-01' => 'SUC-CM',
        'CECO-02' => 'SUC-ZR',
        'CECO-03' => 'SUC-SR',
        'CECO-05' => 'SUC-LL',
        'CECO-06' => 'SUC-AE1',
        'CECO-07' => 'SUC-AE2',
        'CECO-08' => 'SUC-SM',
        'CECO-09' => 'SUC-PV',
        'CECO-10' => 'SUC-SE',
        'CECO-12' => 'SUC-HZ',
        'CECO-13' => 'SUC-OP',
        'CECO-14' => 'SUC-GU',
        'CECO-15' => 'SUC-CO',
        'CECO-16' => 'SUC-PD',
    ];

    public function up(): void
    {
        // 1) Agregar columna
        if (!Schema::connection('pgsql')->hasColumn('centros_costo', 'sucursal_id')) {
            Schema::connection('pgsql')->table('centros_costo', function (Blueprint $table) {
                $table->unsignedBigInteger('sucursal_id')->nullable()->after('padre_id');
                $table->foreign('sucursal_id', 'cecos_sucursal_fk')
                      ->references('id')->on('sucursales')->nullOnDelete();
            });
        }

        // 2) Asignar sucursal_id a los agrupadores
        foreach ($this->mapaAgrupadores as $cecoCodigo => $sucursalCodigo) {
            $sucursalId = DB::connection('pgsql')
                ->table('sucursales')
                ->where('codigo', $sucursalCodigo)
                ->value('id');

            if (!$sucursalId) continue;

            // Agrupador
            DB::connection('pgsql')->table('centros_costo')
                ->where('codigo', $cecoCodigo)
                ->update(['sucursal_id' => $sucursalId]);

            // Hijos: obtener el padre_id del agrupador y actualizar todos sus hijos
            $padreId = DB::connection('pgsql')->table('centros_costo')
                ->where('codigo', $cecoCodigo)
                ->value('id');

            if ($padreId) {
                DB::connection('pgsql')->table('centros_costo')
                    ->where('padre_id', $padreId)
                    ->update(['sucursal_id' => $sucursalId]);
            }
        }
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('centros_costo', function (Blueprint $table) {
            $table->dropForeign('cecos_sucursal_fk');
            $table->dropColumn('sucursal_id');
        });
    }
};
