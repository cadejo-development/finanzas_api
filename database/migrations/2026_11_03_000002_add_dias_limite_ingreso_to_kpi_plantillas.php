<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('kpi_plantillas', function (Blueprint $table) {
            // Días hábiles después del fin del período para registrar resultados.
            // null = usa el último día del período como fecha límite.
            $table->unsignedSmallInteger('dias_limite_ingreso')->nullable()->after('periodicidad');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('kpi_plantillas', function (Blueprint $table) {
            $table->dropColumn('dias_limite_ingreso');
        });
    }
};
