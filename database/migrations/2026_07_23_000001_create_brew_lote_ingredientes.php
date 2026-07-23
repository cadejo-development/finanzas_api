<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('brew_lote_ingredientes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brew_lote_id');
            $table->enum('tipo', ['malta', 'lupulo', 'mineral', 'levadura']);
            $table->string('nombre', 120);
            $table->decimal('cantidad_objetivo', 10, 3)->nullable();
            $table->string('unidad', 10)->nullable();  // lb, g, kg, oz
            $table->string('detalle', 120)->nullable(); // uso para lúpulos (Boil/Whirlpool), fase para minerales
            $table->enum('estado', ['pendiente', 'en_proceso', 'agregado'])->default('pendiente');
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->foreign('brew_lote_id')->references('id')->on('brew_lotes')->onDelete('cascade');
            $table->index(['brew_lote_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('brew_lote_ingredientes');
    }
};
