<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('kpi_plantillas', function (Blueprint $table) {
            // Cómo se evalúa: comparar con meta, contar períodos, sumar logros
            $table->string('tipo_evaluacion', 30)->default('comparar_meta')->after('operador_cumplimiento');
            // Evidencia requerida al registrar resultado
            $table->string('evidencia_tipo', 30)->default('comentario_opcional')->after('tipo_evaluacion');
            // Fuente de información (opcional, orientativo)
            $table->text('fuente_informacion')->nullable()->after('evidencia_tipo');
        });

        // kpi_resultados: agregar comentario y archivo adjunto
        Schema::connection('pgsql')->table('kpi_resultados', function (Blueprint $table) {
            $table->text('comentario')->nullable()->after('monto_bono');
            $table->string('archivo_adjunto', 500)->nullable()->after('comentario');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('kpi_plantillas', function (Blueprint $table) {
            $table->dropColumn(['tipo_evaluacion', 'evidencia_tipo', 'fuente_informacion']);
        });

        Schema::connection('pgsql')->table('kpi_resultados', function (Blueprint $table) {
            $table->dropColumn(['comentario', 'archivo_adjunto']);
        });
    }
};
