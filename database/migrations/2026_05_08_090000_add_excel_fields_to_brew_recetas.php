<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // brew_recetas — metas de volumen + parámetros fermentación adicionales
        Schema::connection('compras')->table('brew_recetas', function (Blueprint $table) {
            $table->integer('cajas_objetivo')->nullable()->comment('Cajas obj. de botellas');
            $table->integer('barriles_objetivo')->nullable()->comment('Barriles obj. (kegs)');
            $table->decimal('temp_maduracion', 5, 1)->nullable()->comment('Temp maduración °C');
            $table->integer('dias_maduracion')->nullable()->comment('Días maduración estimados');
            $table->integer('dias_seguimiento')->nullable()->comment('Días de seguimiento a medir en fermentación');
        });

        // brew_receta_maltas — cantidades de molienda
        Schema::connection('compras')->table('brew_receta_maltas', function (Blueprint $table) {
            $table->decimal('molida_1_kg', 8, 3)->nullable()->comment('Cantidad molida 1 (kg)');
            $table->decimal('molida_2_kg', 8, 3)->nullable()->comment('Cantidad molida 2 (kg)');
        });

        // brew_receta_lupulos — aporte IBU
        Schema::connection('compras')->table('brew_receta_lupulos', function (Blueprint $table) {
            $table->decimal('ibu_aporte', 6, 2)->nullable()->comment('IBU aportado por esta adición');
        });

        // brew_receta_minerales — cantidad Sparge separada
        Schema::connection('compras')->table('brew_receta_minerales', function (Blueprint $table) {
            $table->decimal('cantidad_sparge_g', 8, 3)->nullable()->comment('Cantidad para agua de sparge (g)');
        });

        // brew_receta_levaduras — datos de pitch y generación
        Schema::connection('compras')->table('brew_receta_levaduras', function (Blueprint $table) {
            $table->integer('generacion')->nullable()->comment('Generación de la levadura');
            $table->decimal('viabilidad_pct', 5, 2)->nullable()->comment('% Viabilidad objetivo');
            $table->decimal('vol_pitch', 8, 2)->nullable()->comment('Volumen de pitch (L)');
            $table->decimal('temp_pitch', 5, 1)->nullable()->comment('Temperatura de pitch (°C)');
        });

        // brew_receta_macerado_pasos — adición de agua y pH objetivo
        Schema::connection('compras')->table('brew_receta_macerado_pasos', function (Blueprint $table) {
            $table->decimal('adicion_agua_l', 8, 2)->nullable()->comment('Adición H2O (L)');
            $table->decimal('ph_objetivo', 4, 2)->nullable()->comment('pH objetivo del paso');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_receta_macerado_pasos', function (Blueprint $table) {
            $table->dropColumn(['adicion_agua_l', 'ph_objetivo']);
        });
        Schema::connection('compras')->table('brew_receta_levaduras', function (Blueprint $table) {
            $table->dropColumn(['generacion', 'viabilidad_pct', 'vol_pitch', 'temp_pitch']);
        });
        Schema::connection('compras')->table('brew_receta_minerales', function (Blueprint $table) {
            $table->dropColumn('cantidad_sparge_g');
        });
        Schema::connection('compras')->table('brew_receta_lupulos', function (Blueprint $table) {
            $table->dropColumn('ibu_aporte');
        });
        Schema::connection('compras')->table('brew_receta_maltas', function (Blueprint $table) {
            $table->dropColumn(['molida_1_kg', 'molida_2_kg']);
        });
        Schema::connection('compras')->table('brew_recetas', function (Blueprint $table) {
            $table->dropColumn(['cajas_objetivo', 'barriles_objetivo', 'temp_maduracion', 'dias_maduracion', 'dias_seguimiento']);
        });
    }
};
