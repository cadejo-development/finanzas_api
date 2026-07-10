<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('solicitudes_retractacion', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('desvinculacion_id');
            $table->integer('empleado_id');
            $table->string('empleado_nombre', 200)->nullable();
            $table->string('sucursal_nombre', 200)->nullable();
            $table->string('cargo_nombre', 200)->nullable();
            $table->integer('solicitado_por_empleado_id');   // empleado del gerente
            $table->string('solicitado_por_nombre', 200)->nullable();
            $table->text('justificacion');
            // pendiente | aprobada | rechazada
            $table->string('estado', 30)->default('pendiente');
            $table->integer('revisado_por_empleado_id')->nullable();
            $table->string('revisado_por_nombre', 200)->nullable();
            $table->timestamp('revisado_en')->nullable();
            $table->text('comentario_revision')->nullable();
            $table->string('aud_usuario', 200)->nullable();
            $table->timestamps();

            $table->foreign('desvinculacion_id')
                  ->references('id')
                  ->on('desvinculaciones')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('solicitudes_retractacion');
    }
};
