<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // Cocción — molino, pH, whirlpool, enfriamiento, oxigenación
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            $table->decimal('molino_kg_real', 8, 3)->nullable()->after('temp_mash_real');
            $table->integer('molino_tiempo_min')->nullable()->after('molino_kg_real');
            $table->decimal('ph_mash_real', 4, 2)->nullable()->after('molino_tiempo_min');
            $table->decimal('ph_postboil', 4, 2)->nullable()->after('og_real');
            $table->string('whirlpool_hora', 8)->nullable()->after('tiempo_boil_min');
            $table->decimal('whirlpool_temp', 5, 1)->nullable()->after('whirlpool_hora');
            $table->decimal('enf_temp_entrada', 5, 1)->nullable()->after('whirlpool_temp');
            $table->decimal('enf_temp_refrigerante', 5, 1)->nullable()->after('enf_temp_entrada');
            $table->decimal('enf_temp_salida', 5, 1)->nullable()->after('enf_temp_refrigerante');
            $table->integer('enf_tiempo_min')->nullable()->after('enf_temp_salida');
            $table->decimal('oxig_temp', 5, 1)->nullable()->after('enf_tiempo_min');
            $table->string('oxig_nivel', 30)->nullable()->after('oxig_temp');
        });

        // Pasos macerado — pH real medido
        Schema::connection('compras')->table('brew_lote_macerado_pasos', function (Blueprint $table) {
            $table->decimal('ph_real', 4, 2)->nullable()->after('hora_fin');
        });

        // Corridas filtración — vol inicial/final + timestamp
        Schema::connection('compras')->table('brew_lote_filtracion_corridas', function (Blueprint $table) {
            $table->decimal('vol_inicial', 8, 2)->nullable()->after('numero_corrida');
            $table->decimal('vol_final', 8, 2)->nullable()->after('vol_inicial');
            $table->string('timestamp_inicio', 20)->nullable()->after('hora');
        });

        // Fermentación — D-rest / descanso diacetil
        Schema::connection('compras')->table('brew_lote_fermentacion', function (Blueprint $table) {
            $table->tinyInteger('drest_inicio_dia')->unsigned()->nullable()->after('notas');
            $table->decimal('drest_temp', 5, 1)->nullable()->after('drest_inicio_dia');
            $table->text('drest_resultado')->nullable()->after('drest_temp');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            $table->dropColumn([
                'molino_kg_real', 'molino_tiempo_min', 'ph_mash_real', 'ph_postboil',
                'whirlpool_hora', 'whirlpool_temp',
                'enf_temp_entrada', 'enf_temp_refrigerante', 'enf_temp_salida', 'enf_tiempo_min',
                'oxig_temp', 'oxig_nivel',
            ]);
        });

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
