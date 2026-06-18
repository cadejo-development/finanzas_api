<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('contratos_empleado', function (Blueprint $table) {
            $table->id();
            // FK a empleados en pgsql — sin constraint cross-DB
            $table->unsignedBigInteger('empleado_id');
            $table->string('empleado_nombre', 200);           // desnormalizado para historial
            $table->unsignedBigInteger('ingreso_id')->nullable();
            $table->unsignedBigInteger('tipo_contrato_id')->nullable();
            $table->unsignedBigInteger('plantilla_id')->nullable();
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();             // null = indefinido
            // borrador | activo | firmado | vencido | cancelado
            $table->string('estado', 30)->default('activo');
            $table->text('notas')->nullable();
            $table->unsignedBigInteger('generado_por_id')->nullable();
            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();

            $table->foreign('ingreso_id')
                  ->references('id')
                  ->on('ingresos_personal')
                  ->onDelete('set null');

            $table->foreign('tipo_contrato_id')
                  ->references('id')
                  ->on('tipos_contrato')
                  ->onDelete('set null');

            $table->foreign('plantilla_id')
                  ->references('id')
                  ->on('plantillas_contrato')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('contratos_empleado');
    }
};
