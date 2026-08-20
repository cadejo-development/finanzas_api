<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('planilla_lineas', function (Blueprint $table) {
            $table->decimal('dias_asueto', 5, 2)->default(0)->after('propina_detalle_id');
            $table->decimal('salario_asueto', 10, 2)->default(0)->after('dias_asueto');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('planilla_lineas', function (Blueprint $table) {
            $table->dropColumn(['dias_asueto', 'salario_asueto']);
        });
    }
};
