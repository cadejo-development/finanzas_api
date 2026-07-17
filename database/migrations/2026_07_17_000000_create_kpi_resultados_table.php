<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->create('kpi_resultados', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kpi_plantilla_id');
            $table->unsignedBigInteger('empleado_id');
            $table->date('periodo');                         // primer día del mes: 2026-07-01
            $table->decimal('porcentaje_cumplimiento', 7, 2);
            $table->decimal('valor_real', 15, 2)->nullable(); // valor numérico ingresado (ventas, clientes, etc.)
            $table->decimal('monto_bono', 10, 2)->default(0);
            $table->unsignedBigInteger('bonificacion_id')->nullable(); // ID en bonificaciones
            $table->string('aud_usuario', 150)->nullable();
            $table->timestamps();

            $table->foreign('kpi_plantilla_id')->references('id')->on('kpi_plantillas')->cascadeOnDelete();
            $table->unique(['kpi_plantilla_id', 'empleado_id', 'periodo'], 'kpi_resultados_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('kpi_resultados');
    }
};
