<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // Coccion: agrega numero (1-based) para soportar múltiples cocciones por lote
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            $table->smallInteger('numero')->default(1)->after('brew_lote_id');
        });
        // Backfill: coccion existentes son número 1
        DB::connection('compras')->table('brew_lote_coccion')->update(['numero' => 1]);
        // Unique: un solo registro por (lote, numero)
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            $table->unique(['brew_lote_id', 'numero'], 'brew_lote_coccion_lote_numero_unique');
        });

        // Macerado pasos: agrega coccion_numero para saber a qué cocción pertenece cada paso
        Schema::connection('compras')->table('brew_lote_macerado_pasos', function (Blueprint $table) {
            $table->smallInteger('coccion_numero')->default(1)->after('brew_lote_id');
        });
        DB::connection('compras')->table('brew_lote_macerado_pasos')->update(['coccion_numero' => 1]);

        // Boil pasos: igual
        Schema::connection('compras')->table('brew_lote_boil_pasos', function (Blueprint $table) {
            $table->smallInteger('coccion_numero')->default(1)->after('brew_lote_id');
        });
        DB::connection('compras')->table('brew_lote_boil_pasos')->update(['coccion_numero' => 1]);
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            $table->dropUnique('brew_lote_coccion_lote_numero_unique');
            $table->dropColumn('numero');
        });
        Schema::connection('compras')->table('brew_lote_macerado_pasos', function (Blueprint $table) {
            $table->dropColumn('coccion_numero');
        });
        Schema::connection('compras')->table('brew_lote_boil_pasos', function (Blueprint $table) {
            $table->dropColumn('coccion_numero');
        });
    }
};
