<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        // Flag que determina si el cargo participa en la distribución de propinas
        Schema::connection('rrhh')->table('propina_puntos_cargo', function (Blueprint $table) {
            $table->boolean('aplica_propina')->default(false)->after('puntos_propina');
        });

        // Override por empleado: null = hereda del cargo, true/false = fuerza inclusión/exclusión
        Schema::connection('rrhh')->table('propina_puntos_empleado', function (Blueprint $table) {
            $table->boolean('aplica_propina_override')->nullable()->after('puntos_propina');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('propina_puntos_empleado', function (Blueprint $table) {
            $table->dropColumn('aplica_propina_override');
        });

        Schema::connection('rrhh')->table('propina_puntos_cargo', function (Blueprint $table) {
            $table->dropColumn('aplica_propina');
        });
    }
};
