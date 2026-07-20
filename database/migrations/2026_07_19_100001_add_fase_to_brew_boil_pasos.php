<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('brew_receta_boil_pasos', function (Blueprint $table) {
            $table->string('fase', 20)->default('hervor')->after('tiempo_min')
                ->comment('hervor|whirlpool');
        });

        Schema::connection('compras')->table('brew_lote_boil_pasos', function (Blueprint $table) {
            $table->string('fase', 20)->default('hervor')->after('completado')
                ->comment('hervor|whirlpool');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_receta_boil_pasos', function (Blueprint $table) {
            $table->dropColumn('fase');
        });
        Schema::connection('compras')->table('brew_lote_boil_pasos', function (Blueprint $table) {
            $table->dropColumn('fase');
        });
    }
};
