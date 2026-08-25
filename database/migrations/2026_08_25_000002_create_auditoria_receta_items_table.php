<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('auditoria_receta_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('auditoria_id');
            $table->unsignedBigInteger('receta_id')->nullable();
            $table->string('tipo_receta', 20)->default('plato');
            $table->unsignedBigInteger('estacion_id')->nullable();
            $table->unsignedBigInteger('responsable_id')->nullable();
            $table->string('responsable_nombre', 200)->nullable();
            $table->string('receta_nombre', 300)->nullable();
            $table->decimal('calificacion', 5, 1)->nullable();
            $table->string('clasificacion', 50)->nullable();
            $table->timestamps();

            $table->foreign('auditoria_id')->references('id')->on('auditorias_receta')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('auditoria_receta_items');
    }
};
