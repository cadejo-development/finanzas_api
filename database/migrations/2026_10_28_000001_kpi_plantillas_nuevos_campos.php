<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // kpi_plantillas: comentarios, periodicidad, cargo_responsable_id
        Schema::connection('pgsql')->table('kpi_plantillas', function (Blueprint $table) {
            $table->text('comentarios')->nullable()->after('descripcion');
            $table->string('periodicidad', 20)->default('mensual')->after('tiene_bono');
            $table->foreignId('cargo_responsable_id')
                  ->nullable()
                  ->after('periodicidad')
                  ->constrained('cargos')
                  ->nullOnDelete();
        });

        // kpi_plantilla_cargos: peso del KPI para ese puesto
        Schema::connection('pgsql')->table('kpi_plantilla_cargos', function (Blueprint $table) {
            $table->decimal('peso', 5, 2)->nullable()->after('cargo_id');
        });

        // kpi_escala_bonificacion: descripcion de nivel + valor nullable
        Schema::connection('pgsql')->table('kpi_escala_bonificacion', function (Blueprint $table) {
            $table->string('descripcion', 100)->nullable()->after('porcentaje_desde');
        });

        // Hacer valor nullable en la escala (para rangos sin monto económico aún)
        DB::connection('pgsql')->statement(
            'ALTER TABLE kpi_escala_bonificacion ALTER COLUMN valor DROP NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('kpi_plantillas', function (Blueprint $table) {
            $table->dropForeign(['cargo_responsable_id']);
            $table->dropColumn(['comentarios', 'periodicidad', 'cargo_responsable_id']);
        });

        Schema::connection('pgsql')->table('kpi_plantilla_cargos', function (Blueprint $table) {
            $table->dropColumn('peso');
        });

        Schema::connection('pgsql')->table('kpi_escala_bonificacion', function (Blueprint $table) {
            $table->dropColumn('descripcion');
        });

        DB::connection('pgsql')->statement(
            'ALTER TABLE kpi_escala_bonificacion ALTER COLUMN valor SET NOT NULL'
        );
    }
};
