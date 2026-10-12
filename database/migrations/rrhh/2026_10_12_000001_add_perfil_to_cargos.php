<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->table('cargos', function (Blueprint $table) {
            $table->text('descripcion')->nullable()->after('nombre');
            $table->text('funciones')->nullable()->after('descripcion');
            $table->string('horario', 200)->nullable()->after('funciones');
            $table->text('requisitos')->nullable()->after('horario');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('cargos', function (Blueprint $table) {
            $table->dropColumn(['descripcion', 'funciones', 'horario', 'requisitos']);
        });
    }
};
