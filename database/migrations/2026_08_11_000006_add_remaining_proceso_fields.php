<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // ph_real medido en cada paso de macerado
        if (!Schema::connection('compras')->hasColumn('brew_lote_macerado_pasos', 'ph_real')) {
            Schema::connection('compras')->table('brew_lote_macerado_pasos', function (Blueprint $table) {
                $table->decimal('ph_real', 4, 2)->nullable();
            });
        }

        // Vol inicial/final y timestamp en corridas de filtración
        Schema::connection('compras')->table('brew_lote_filtracion_corridas', function (Blueprint $table) {
            if (!Schema::connection('compras')->hasColumn('brew_lote_filtracion_corridas', 'vol_inicial'))
                $table->decimal('vol_inicial', 8, 2)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_filtracion_corridas', 'vol_final'))
                $table->decimal('vol_final', 8, 2)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_filtracion_corridas', 'timestamp_inicio'))
                $table->string('timestamp_inicio', 20)->nullable();
        });

        // D-rest / descanso diacetil en fermentación
        Schema::connection('compras')->table('brew_lote_fermentacion', function (Blueprint $table) {
            if (!Schema::connection('compras')->hasColumn('brew_lote_fermentacion', 'drest_inicio_dia'))
                $table->tinyInteger('drest_inicio_dia')->unsigned()->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_fermentacion', 'drest_temp'))
                $table->decimal('drest_temp', 5, 1)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_fermentacion', 'drest_resultado'))
                $table->text('drest_resultado')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_lote_macerado_pasos', function (Blueprint $table) {
            $table->dropColumn('ph_real');
        });
        Schema::connection('compras')->table('brew_lote_filtracion_corridas', function (Blueprint $table) {
            $table->dropColumn(['vol_inicial', 'vol_final', 'timestamp_inicio']);
        });
        Schema::connection('compras')->table('brew_lote_fermentacion', function (Blueprint $table) {
            $table->dropColumn(['drest_inicio_dia', 'drest_temp', 'drest_resultado']);
        });
    }
};
