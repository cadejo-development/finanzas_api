<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('horas_extras_solicitudes', function (Blueprint $table) {
            $table->time('hora_inicio')->nullable()->after('fecha_fin');
            $table->time('hora_fin')->nullable()->after('hora_inicio');
            $table->string('motivo', 500)->nullable()->after('descripcion');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('horas_extras_solicitudes', function (Blueprint $table) {
            $table->dropColumn(['hora_inicio', 'hora_fin', 'motivo']);
        });
    }
};
