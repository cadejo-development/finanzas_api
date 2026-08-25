<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        if (Schema::connection('compras')->hasTable('auditoria_receta_criterios')) return;
        Schema::connection('compras')->create('auditoria_receta_criterios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('auditoria_id');
            $table->unsignedBigInteger('receta_item_id');
            $table->unsignedBigInteger('criterio_id');
            $table->string('resultado', 20)->nullable();   // cumple | no_cumple | na
            $table->text('observaciones')->nullable();
            $table->string('foto_url', 1000)->nullable();
            $table->timestamps();

            $table->foreign('auditoria_id')->references('id')->on('auditorias_receta')->onDelete('cascade');
            $table->foreign('receta_item_id')->references('id')->on('auditoria_receta_items')->onDelete('cascade');
            $table->unique(['receta_item_id', 'criterio_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('auditoria_receta_criterios');
    }
};
