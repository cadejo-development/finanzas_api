<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('rrhh')->create('expediente_experiencia_laboral', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('empleado_id')->index();
            $table->string('empresa', 200);
            $table->string('cargo', 200)->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->boolean('es_actual')->default(false);
            $table->text('descripcion')->nullable();
            $table->string('pais', 80)->default('El Salvador');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('expediente_experiencia_laboral');
    }
};
