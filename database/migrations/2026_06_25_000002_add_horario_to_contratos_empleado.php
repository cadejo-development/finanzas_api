<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('rrhh')->table('contratos_empleado', function (Blueprint $table) {
            $table->string('horario', 200)->nullable()->after('funciones');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('contratos_empleado', function (Blueprint $table) {
            $table->dropColumn('horario');
        });
    }
};
