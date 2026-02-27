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
        if (!Schema::connection('pagos')->hasTable('pedidos')) {
            Schema::connection('pagos')->create('pedidos', function (Blueprint $table) {
                $table->id();
                $table->string('sucursal_codigo', 20); // Código de sucursal (no FK)
                $table->string('centro_costo_codigo', 20)->nullable(); // Código de centro de costo (no FK)
                $table->date('semana_inicio');
                $table->date('semana_fin');
                $table->string('estado', 20);
                $table->decimal('total_estimado', 12, 2)->nullable();
                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();
                $table->unique(['sucursal_codigo', 'semana_inicio']);
                $table->index(['estado', 'semana_inicio']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
