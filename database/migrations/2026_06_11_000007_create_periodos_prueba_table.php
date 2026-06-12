<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('periodos_prueba', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ingreso_id');           // FK a ingresos_personal
            $table->unsignedBigInteger('empleado_id');
            $table->date('fecha_inicio');
            $table->date('fecha_fin_estimada');                 // fecha_inicio + 90 días por defecto
            $table->unsignedBigInteger('responsable_id')->nullable(); // user_id del evaluador

            // en_prueba | aprobado | no_aprobado | renuncia | desvinculado
            $table->string('estado')->default('en_prueba');

            $table->text('comentarios')->nullable();
            $table->timestamp('evaluado_en')->nullable();
            $table->unsignedBigInteger('evaluado_por_id')->nullable();

            // Control de alertas ya enviadas (evitar duplicados)
            $table->boolean('alerta_15_enviada')->default(false);
            $table->boolean('alerta_7_enviada')->default(false);
            $table->boolean('alerta_sin_eval_enviada')->default(false);

            $table->string('aud_usuario')->nullable();
            $table->timestamps();

            $table->foreign('ingreso_id')
                  ->references('id')
                  ->on('ingresos_personal')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('periodos_prueba');
    }
};
