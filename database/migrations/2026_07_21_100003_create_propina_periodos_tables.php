<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('propina_periodos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sucursal_id');
            $table->integer('anio');
            $table->integer('mes');
            $table->integer('quincena'); // 1 o 2
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->integer('dias_quincena')->default(15);

            // Inputs de usuario
            $table->decimal('venta_quincena', 14, 2)->nullable();
            $table->decimal('propina_total_recolectada', 12, 2)->nullable();

            // Calculados automáticamente
            $table->decimal('propina_tabla', 12, 2)->nullable();
            $table->decimal('puntos_totales', 8, 2)->nullable();
            $table->decimal('valor_punto_propina', 10, 4)->nullable();
            $table->decimal('propina_repartida', 12, 2)->nullable();
            $table->decimal('retencion_monto', 12, 2)->nullable();
            $table->decimal('retencion_pct', 5, 4)->nullable();
            $table->decimal('excedente_vs_tabla', 12, 2)->nullable();
            $table->decimal('sobrante_generado', 12, 2)->nullable();
            $table->decimal('sobrante_aplicado_monto', 12, 2)->nullable();

            // Control de flujo
            $table->string('estado', 20)->default('borrador'); // borrador|calculado|aprobado|integrado
            $table->unsignedBigInteger('planilla_id')->nullable();
            $table->unsignedBigInteger('elaborado_por')->nullable();
            $table->unsignedBigInteger('aprobado_por')->nullable();
            $table->timestamp('aprobado_en')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->unique(['sucursal_id', 'anio', 'mes', 'quincena']);
        });

        Schema::connection('rrhh')->create('propina_detalles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('periodo_id');
            $table->unsignedBigInteger('empleado_id');

            // Puntos resueltos al momento del cálculo
            $table->decimal('puntos_propina', 4, 2)->nullable();
            $table->string('fuente_puntos', 20)->default('cargo'); // cargo|override|manual

            // Días
            $table->integer('dias_quincena')->default(15);
            $table->decimal('dias_no_laborados', 5, 2)->default(0);
            $table->decimal('dias_laborados', 5, 2)->nullable();
            $table->boolean('override_dias')->default(false);
            $table->jsonb('detalle_ausencias')->nullable();

            // Montos
            $table->decimal('propina_diaria', 10, 4)->nullable();
            $table->decimal('propina_quincena', 10, 2)->nullable();
            $table->decimal('sobrante_aplicado', 10, 2)->default(0);
            $table->decimal('propina_adicional', 10, 2)->default(0);
            $table->decimal('total_propina', 10, 2)->nullable();

            // Control
            $table->boolean('incluido')->default(true);
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->foreign('periodo_id')->references('id')->on('propina_periodos')->onDelete('cascade');
            $table->unique(['periodo_id', 'empleado_id']);
        });

        Schema::connection('rrhh')->create('propina_sobrantes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('periodo_origen_id');
            $table->decimal('monto_original', 12, 2);
            $table->decimal('monto_distribuido', 12, 2)->default(0);
            $table->decimal('monto_pendiente', 12, 2);
            $table->unsignedBigInteger('periodo_distribucion_id')->nullable();
            $table->string('estado', 20)->default('pendiente'); // pendiente|parcial|distribuido
            $table->timestamps();

            $table->foreign('periodo_origen_id')->references('id')->on('propina_periodos');
            $table->index(['sucursal_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('propina_sobrantes');
        Schema::connection('rrhh')->dropIfExists('propina_detalles');
        Schema::connection('rrhh')->dropIfExists('propina_periodos');
    }
};
