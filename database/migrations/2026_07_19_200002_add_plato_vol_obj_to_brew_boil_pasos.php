<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // Receta: objetivos de plato y volumen por adición
        Schema::connection('compras')->table('brew_receta_boil_pasos', function (Blueprint $table) {
            $table->decimal('plato_objetivo', 5, 2)->nullable()->after('cantidad_objetivo')
                ->comment('°Plato esperado al momento de la adición');
            $table->decimal('vol_objetivo_l', 8, 2)->nullable()->after('plato_objetivo')
                ->comment('Volumen del kettle esperado (L) al momento de la adición');
        });

        // Lote: objetivos copiados de receta + campos reales adicionales
        Schema::connection('compras')->table('brew_lote_boil_pasos', function (Blueprint $table) {
            $table->decimal('plato_objetivo', 5, 2)->nullable()->after('cantidad_objetivo')
                ->comment('Copiado de receta');
            $table->decimal('vol_objetivo_l', 8, 2)->nullable()->after('plato_objetivo')
                ->comment('Copiado de receta');
            $table->string('ingrediente_real', 200)->nullable()->after('timestamp_adicion')
                ->comment('Ingrediente real utilizado (si difiere del planificado)');
            $table->integer('t_transcu_real')->nullable()->after('ingrediente_real')
                ->comment('Tiempo transcurrido real (min desde inicio del boil/WP)');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_receta_boil_pasos', function (Blueprint $table) {
            $table->dropColumn(['plato_objetivo', 'vol_objetivo_l']);
        });
        Schema::connection('compras')->table('brew_lote_boil_pasos', function (Blueprint $table) {
            $table->dropColumn(['plato_objetivo', 'vol_objetivo_l', 'ingrediente_real', 't_transcu_real']);
        });
    }
};
