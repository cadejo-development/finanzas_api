<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('kpi_escala_bonificacion', function (Blueprint $table) {
            $table->string('operador', 2)->default('>=')->after('porcentaje_desde');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('kpi_escala_bonificacion', function (Blueprint $table) {
            $table->dropColumn('operador');
        });
    }
};
