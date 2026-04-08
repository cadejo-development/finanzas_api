<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('rrhh')->create('ausencias_injustificadas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empleado_id');
            $table->unsignedBigInteger('registrado_por_id')->nullable();
            $table->date('fecha');
            $table->text('descripcion')->nullable();
            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();

            $table->index(['empleado_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('ausencias_injustificadas');
    }
};
