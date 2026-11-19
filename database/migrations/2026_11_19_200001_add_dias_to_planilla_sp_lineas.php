<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('planilla_sp_lineas', function (Blueprint $table) {
            // Días del período desde que el empleado ingresó hasta fin de quincena
            $table->smallInteger('dias_periodo')->nullable()->after('empleado_id');
            // Días de permiso aprobados (día completo) dentro del período
            $table->smallInteger('dias_permiso')->default(0)->after('dias_periodo');
            // Días de incapacidad dentro del período
            $table->smallInteger('dias_incapacidad')->default(0)->after('dias_permiso');
            // Días efectivamente trabajados = dias_periodo - dias_permiso - dias_incapacidad
            $table->smallInteger('dias_trabajados')->nullable()->after('dias_incapacidad');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('planilla_sp_lineas', function (Blueprint $table) {
            $table->dropColumn(['dias_periodo', 'dias_permiso', 'dias_incapacidad', 'dias_trabajados']);
        });
    }
};
