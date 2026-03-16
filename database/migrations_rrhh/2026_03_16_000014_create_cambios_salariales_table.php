<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'rrhh'; }

    public function up(): void
    {
        if (!Schema::connection('rrhh')->hasTable('cambios_salariales')) {
            Schema::connection('rrhh')->create('cambios_salariales', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('empleado_id');
                $table->unsignedBigInteger('solicitado_por_id')->comment('jefe_id que solicita');
                $table->unsignedBigInteger('tipo_aumento_id');

                $table->decimal('salario_anterior', 10, 2);
                $table->decimal('salario_nuevo', 10, 2);
                $table->decimal('porcentaje', 6, 2)->nullable()->comment('Calculado automáticamente');

                $table->date('fecha_efectiva');
                $table->text('justificacion')->nullable();

                // estado: pendiente | aprobado | rechazado
                $table->string('estado', 20)->default('pendiente');

                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('cambios_salariales');
    }
};
