<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'rrhh'; }

    public function up(): void
    {
        if (!Schema::connection('rrhh')->hasTable('permisos')) {
            Schema::connection('rrhh')->create('permisos', function (Blueprint $table) {
                $table->id();
                // Referencias a core (sin FK cross-db)
                $table->unsignedBigInteger('empleado_id');
                $table->unsignedBigInteger('jefe_id')->comment('Empleado que registra/aprueba');
                $table->unsignedBigInteger('tipo_permiso_id');

                $table->date('fecha');

                // Horas parciales (nullable si es día completo)
                $table->boolean('es_dia_completo')->default(true);
                $table->time('hora_inicio')->nullable();
                $table->time('hora_fin')->nullable();
                $table->decimal('horas_solicitadas', 4, 2)->nullable()->comment('Calculado de hora_inicio a hora_fin');

                // Días (para permisos de varios días)
                $table->decimal('dias', 4, 1)->nullable()->comment('Null cuando es permiso por horas');

                $table->text('motivo')->nullable();

                // estado: pendiente | aprobado | rechazado
                $table->string('estado', 20)->default('pendiente');
                $table->text('observaciones_jefe')->nullable();

                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('permisos');
    }
};
