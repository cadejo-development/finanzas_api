<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Almacena las cantidades reales de maltas por cocción (puede ser desigual entre cocciones).
 * JSON: [{id, nombre, unidad, sugerido, real}, ...]
 * Propaga del paso molienda al macerado para que el operario sepa qué se molió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            $table->text('molino_maltas_json')->nullable()->after('molino_notas');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            $table->dropColumn('molino_maltas_json');
        });
    }
};
