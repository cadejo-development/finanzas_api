<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('periodos_prueba', function (Blueprint $table) {
            $table->boolean('alerta_1_enviada')->default(false)->after('alerta_sin_eval_enviada');
            $table->decimal('puntaje_evaluacion', 5, 2)->nullable()->after('alerta_1_enviada');
            $table->jsonb('respuestas_evaluacion')->nullable()->after('puntaje_evaluacion');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('periodos_prueba', function (Blueprint $table) {
            $table->dropColumn(['alerta_1_enviada', 'puntaje_evaluacion', 'respuestas_evaluacion']);
        });
    }
};
