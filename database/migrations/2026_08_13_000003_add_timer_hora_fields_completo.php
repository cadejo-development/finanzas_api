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
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'molino_hora_inicio'))
                $table->string('molino_hora_inicio', 8)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'molino_hora_fin'))
                $table->string('molino_hora_fin', 8)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'wp_tiempo_min'))
                $table->integer('wp_tiempo_min')->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'wp_hora_inicio'))
                $table->string('wp_hora_inicio', 8)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'wp_hora_fin'))
                $table->string('wp_hora_fin', 8)->nullable();
        });

        Schema::connection('compras')->table('brew_lote_fermentacion', function (Blueprint $table) {
            if (!Schema::connection('compras')->hasColumn('brew_lote_fermentacion', 'pitch_tiempo_min'))
                $table->integer('pitch_tiempo_min')->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_fermentacion', 'pitch_hora_inicio'))
                $table->string('pitch_hora_inicio', 8)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_fermentacion', 'pitch_hora_fin'))
                $table->string('pitch_hora_fin', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            $table->dropColumn(['molino_hora_inicio', 'molino_hora_fin', 'wp_tiempo_min', 'wp_hora_inicio', 'wp_hora_fin']);
        });
        Schema::connection('compras')->table('brew_lote_fermentacion', function (Blueprint $table) {
            $table->dropColumn(['pitch_tiempo_min', 'pitch_hora_inicio', 'pitch_hora_fin']);
        });
    }
};
