<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('planilla_movimientos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('planilla_id');
            $table->unsignedBigInteger('empleado_id');

            // Tipo de movimiento: qué evento causó este impacto
            $table->string('tipo', 40); // permiso_sin_goce|permiso_con_goce|incapacidad_isss|incapacidad_privada|vacacion|ausencia|suspension|asueto|horas_extras

            $table->string('concepto', 200)->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            // dias: positivo = suma (asueto/horas_extras), negativo = resta (ausencias/permisos sin goce)
            $table->decimal('dias', 6, 2)->default(0);
            $table->decimal('monto', 10, 2)->nullable(); // impacto monetario (asueto, horas extras)
            $table->unsignedBigInteger('referencia_id')->nullable(); // ID del registro fuente

            $table->timestamps();

            $table->foreign('planilla_id')->references('id')->on('planillas')->onDelete('cascade');
            $table->index(['planilla_id', 'empleado_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('planilla_movimientos');
    }
};
