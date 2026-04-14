<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Horarios semanales de empleados.
 * Un registro por empleado × día. El frontend agrupa por semana.
 *
 * tipos: normal | libre | vacacion | dia_cadejo | incapacidad
 */
return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        Schema::connection('pgsql')->create('horarios_empleado', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empleado_id');
            $table->date('fecha');
            $table->time('hora_inicio')->nullable();
            $table->time('hora_fin')->nullable();
            $table->string('tipo', 20)->default('normal');
            // tipo: normal | libre | vacacion | dia_cadejo | incapacidad
            $table->string('notas', 200)->nullable();
            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();

            $table->unique(['empleado_id', 'fecha'], 'horarios_emp_fecha_unique');

            $table->foreign('empleado_id', 'horarios_emp_fk')
                  ->references('id')
                  ->on('empleados')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('horarios_empleado');
    }
};
