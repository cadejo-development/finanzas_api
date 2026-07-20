<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // Receta: cantidad objetivo de cada adición de hervor/WP
        Schema::connection('compras')->table('brew_receta_boil_pasos', function (Blueprint $table) {
            $table->decimal('cantidad_objetivo', 10, 3)->nullable()->after('tiempo_min')
                ->comment('Cantidad objetivo (kg, g, mL, L, etc.)');
            $table->string('unidad', 20)->nullable()->after('cantidad_objetivo')
                ->comment('Unidad de medida: kg, g, mL, L, unidades');
        });

        // Lote: tracking real de cada adición hervor/WP
        Schema::connection('compras')->table('brew_lote_boil_pasos', function (Blueprint $table) {
            $table->decimal('cantidad_objetivo', 10, 3)->nullable()->after('fase')
                ->comment('Copiado de la receta');
            $table->string('unidad', 20)->nullable()->after('cantidad_objetivo');
            $table->string('timestamp_adicion', 20)->nullable()->after('unidad')
                ->comment('Hora real en que se agregó');
            $table->decimal('cantidad_real', 10, 3)->nullable()->after('timestamp_adicion')
                ->comment('Cantidad realmente agregada');
            $table->decimal('plato_real', 5, 2)->nullable()->after('cantidad_real')
                ->comment('Lectura de °Plato al momento de la adición');
            $table->decimal('vol_real_l', 8, 2)->nullable()->after('plato_real')
                ->comment('Volumen real del kettle en ese momento (L)');
            $table->text('notas')->nullable()->after('vol_real_l');
        });

        // Lote: tracking macerado — vol agua real + adiciones + notas
        Schema::connection('compras')->table('brew_lote_macerado_pasos', function (Blueprint $table) {
            $table->decimal('ph_objetivo', 4, 2)->nullable()->after('ph_real')
                ->comment('pH objetivo copiado de la receta');
            $table->decimal('adicion_agua_l', 8, 2)->nullable()->after('ph_objetivo')
                ->comment('Adición H2O objetivo (L) copiado de receta');
            $table->decimal('vol_agua_real_l', 8, 2)->nullable()->after('adicion_agua_l')
                ->comment('Volumen de agua realmente adicionado (L)');
            $table->text('adiciones_reales')->nullable()->after('vol_agua_real_l')
                ->comment('Minerales, ácidos u otras adiciones realizadas');
            $table->text('notas')->nullable()->after('adiciones_reales');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_receta_boil_pasos', function (Blueprint $table) {
            $table->dropColumn(['cantidad_objetivo', 'unidad']);
        });
        Schema::connection('compras')->table('brew_lote_boil_pasos', function (Blueprint $table) {
            $table->dropColumn([
                'cantidad_objetivo', 'unidad', 'timestamp_adicion',
                'cantidad_real', 'plato_real', 'vol_real_l', 'notas',
            ]);
        });
        Schema::connection('compras')->table('brew_lote_macerado_pasos', function (Blueprint $table) {
            $table->dropColumn(['ph_objetivo', 'adicion_agua_l', 'vol_agua_real_l', 'adiciones_reales', 'notas']);
        });
    }
};
