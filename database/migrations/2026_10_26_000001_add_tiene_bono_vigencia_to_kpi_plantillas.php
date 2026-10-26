<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('kpi_plantillas', function (Blueprint $table) {
            $table->boolean('tiene_bono')->default(false)->after('activo');
            $table->date('vigencia_desde')->nullable()->after('tiene_bono');
            $table->date('vigencia_hasta')->nullable()->after('vigencia_desde');
            // monto_bono_base ya es nullable, sin cambio
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('kpi_plantillas', function (Blueprint $table) {
            $table->dropColumn(['tiene_bono', 'vigencia_desde', 'vigencia_hasta']);
        });
    }
};
