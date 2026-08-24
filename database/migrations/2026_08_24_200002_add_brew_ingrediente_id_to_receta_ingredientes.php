<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula cada ingrediente de receta al catálogo brew_ingredientes (que tiene el código Brilo).
 * Nullable: el nombre libre se mantiene como fallback, el vínculo es opcional.
 */
return new class extends Migration
{
    private array $tablas = [
        'brew_receta_maltas'    => 'brm_ing_fk',
        'brew_receta_lupulos'   => 'brl_ing_fk',
        'brew_receta_minerales' => 'brmn_ing_fk',
        'brew_receta_levaduras' => 'brlev_ing_fk',
    ];

    public function up(): void
    {
        foreach ($this->tablas as $tabla => $fkName) {
            Schema::connection('compras')->table($tabla, function (Blueprint $table) use ($fkName) {
                $table->unsignedBigInteger('brew_ingrediente_id')->nullable()->after('nombre');
                $table->foreign('brew_ingrediente_id', $fkName)
                      ->references('id')->on('brew_ingredientes')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tablas as $tabla => $fkName) {
            Schema::connection('compras')->table($tabla, function (Blueprint $table) use ($fkName) {
                $table->dropForeign($fkName);
                $table->dropColumn('brew_ingrediente_id');
            });
        }
    }
};
