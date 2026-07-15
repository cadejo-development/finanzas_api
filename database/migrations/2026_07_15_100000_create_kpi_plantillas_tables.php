<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Plantillas de KPI ────────────────────────────────────────────────────
        Schema::connection('pgsql')->create('kpi_plantillas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->foreignId('sucursal_id')
                  ->nullable()
                  ->constrained('sucursales')
                  ->nullOnDelete();           // null = aplica a todas las sucursales
            $table->string('unidad_medida', 80)->default('unidades');
            $table->decimal('monto_objetivo', 10, 2)->nullable(); // monto base para escalas tipo porcentaje_bono
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 100)->default('sistema');
            $table->timestamps();
        });

        // ── Cargos que participan en cada KPI ───────────────────────────────────
        Schema::connection('pgsql')->create('kpi_plantilla_cargos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_plantilla_id')
                  ->constrained('kpi_plantillas')
                  ->cascadeOnDelete();
            $table->foreignId('cargo_id')
                  ->constrained('cargos')
                  ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['kpi_plantilla_id', 'cargo_id']);
        });

        // ── Escala de bonificación por cumplimiento ──────────────────────────────
        Schema::connection('pgsql')->create('kpi_escala_bonificacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_plantilla_id')
                  ->constrained('kpi_plantillas')
                  ->cascadeOnDelete();
            $table->decimal('porcentaje_desde', 5, 2);   // ej. 80.00
            $table->string('tipo', 30);                   // 'porcentaje_bono' | 'monto_fijo'
            $table->decimal('valor', 10, 2);              // % del monto_objetivo O monto fijo en $
            $table->smallInteger('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('kpi_escala_bonificacion');
        Schema::connection('pgsql')->dropIfExists('kpi_plantilla_cargos');
        Schema::connection('pgsql')->dropIfExists('kpi_plantillas');
    }
};
