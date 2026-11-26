<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('rrhh')->table('empleados', function (Blueprint $table) {
            $table->date('fecha_contrato_sp')->nullable()->after('fecha_ingreso');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('empleados', function (Blueprint $table) {
            $table->dropColumn('fecha_contrato_sp');
        });
    }
};
