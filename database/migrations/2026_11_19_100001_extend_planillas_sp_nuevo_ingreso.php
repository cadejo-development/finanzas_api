<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('planillas_sp', function (Blueprint $table) {
            // 1 = primera quincena (1-15), 2 = segunda quincena (16-fin)
            $table->tinyInteger('quincena')->nullable()->after('mes');
        });

        Schema::connection('rrhh')->table('planilla_sp_lineas', function (Blueprint $table) {
            // Referencia al empleado cuando es nuevo ingreso; null para SP externos
            $table->unsignedBigInteger('empleado_id')->nullable()->after('planilla_sp_id');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('planilla_sp_lineas', function (Blueprint $table) {
            $table->dropColumn('empleado_id');
        });

        Schema::connection('rrhh')->table('planillas_sp', function (Blueprint $table) {
            $table->dropColumn('quincena');
        });
    }
};
