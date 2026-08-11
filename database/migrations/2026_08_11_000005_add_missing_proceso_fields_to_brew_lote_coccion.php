<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'molino_kg_real'))
                $table->decimal('molino_kg_real', 8, 3)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'molino_tiempo_min'))
                $table->integer('molino_tiempo_min')->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'molino_notas'))
                $table->text('molino_notas')->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'macerado_tiempo_min'))
                $table->integer('macerado_tiempo_min')->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'ph_mash_real'))
                $table->decimal('ph_mash_real', 4, 2)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'ph_postboil'))
                $table->decimal('ph_postboil', 4, 2)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'whirlpool_hora'))
                $table->string('whirlpool_hora', 8)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'whirlpool_temp'))
                $table->decimal('whirlpool_temp', 5, 1)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'enf_temp_entrada'))
                $table->decimal('enf_temp_entrada', 5, 1)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'enf_temp_refrigerante'))
                $table->decimal('enf_temp_refrigerante', 5, 1)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'enf_temp_salida'))
                $table->decimal('enf_temp_salida', 5, 1)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'enf_tiempo_min'))
                $table->integer('enf_tiempo_min')->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'oxig_temp'))
                $table->decimal('oxig_temp', 5, 1)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'oxig_nivel'))
                $table->string('oxig_nivel', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            $table->dropColumn([
                'molino_kg_real', 'molino_tiempo_min', 'molino_notas', 'macerado_tiempo_min',
                'ph_mash_real', 'ph_postboil',
                'whirlpool_hora', 'whirlpool_temp',
                'enf_temp_entrada', 'enf_temp_refrigerante', 'enf_temp_salida', 'enf_tiempo_min',
                'oxig_temp', 'oxig_nivel',
            ]);
        });
    }
};
