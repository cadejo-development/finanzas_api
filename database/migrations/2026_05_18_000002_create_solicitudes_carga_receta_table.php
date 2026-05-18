<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('compras')->create('solicitudes_carga_receta', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('fecha_requerida');
            $table->text('nota')->nullable();
            $table->string('estado', 20)->default('pendiente'); // pendiente | completada
            $table->string('solicitado_por', 200);
            $table->string('solicitado_por_nombre', 200)->nullable();
            $table->json('receta_ids');
            $table->json('receta_nombres')->nullable();
            $table->integer('total_recetas')->default(0);
            $table->string('aud_usuario', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('solicitudes_carga_receta');
    }
};
