<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->foreignId('centro_costo_id')->nullable()->constrained('centros_costo');
            $table->date('semana_inicio');
            $table->date('semana_fin');
            $table->string('estado', 20);
            $table->decimal('total_estimado', 12, 2)->nullable();
            $table->string('aud_usuario', 150)->nullable();
            $table->timestamps();
            $table->unique(['sucursal_id', 'semana_inicio']);
            $table->index(['estado', 'semana_inicio']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
