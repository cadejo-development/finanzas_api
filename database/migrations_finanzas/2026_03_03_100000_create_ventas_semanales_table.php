<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla principal de ventas semanales importadas por sucursal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('compras')->create('ventas_semanales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sucursal_id');
            $table->date('semana_inicio');           // lunes de la semana
            $table->string('archivo_nombre')->nullable();
            $table->string('importado_por')->nullable();
            $table->timestamps();

            // No duplicar importación para la misma semana + sucursal
            $table->unique(['sucursal_id', 'semana_inicio'], 'uq_ventas_semana_sucursal');

            $table->index('semana_inicio');
        });

        Schema::connection('compras')->create('ventas_semanales_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_semanal_id')
                  ->constrained('ventas_semanales')
                  ->cascadeOnDelete();
            $table->string('producto_codigo');
            $table->string('producto_nombre');
            $table->string('categoria_key')->nullable();
            $table->decimal('cantidad_vendida', 10, 2)->default(0);
            $table->decimal('precio_unitario', 10, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['venta_semanal_id', 'producto_codigo']);
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('ventas_semanales_detalle');
        Schema::connection('compras')->dropIfExists('ventas_semanales');
    }
};
