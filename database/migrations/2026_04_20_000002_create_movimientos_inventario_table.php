<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        if (!Schema::connection('compras')->hasTable('movimientos_inventario')) {
            Schema::connection('compras')->create('movimientos_inventario', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sucursal_id');
                $table->unsignedBigInteger('producto_id');

                // Tipo de movimiento
                $table->string('tipo', 30);
                // carga_inicial   → se cargó Excel
                // consumo         → calculado desde ventas semanales
                // merma           → pérdida/desperdicio manual
                // ajuste          → corrección manual (positiva o negativa)

                // Cantidad del movimiento en unidad indicada
                // positivo = entrada, negativo = salida
                $table->decimal('cantidad', 12, 4);
                $table->string('unidad', 30);
                // Cantidad normalizada a unidad_base
                $table->decimal('cantidad_base', 14, 6);

                $table->string('motivo', 500)->nullable();
                $table->date('fecha');

                // Referencia opcional al origen del movimiento
                $table->string('referencia_tipo', 50)->nullable();  // venta_semanal | manual
                $table->unsignedBigInteger('referencia_id')->nullable();

                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();

                $table->index(['sucursal_id', 'producto_id']);
                $table->index(['tipo', 'fecha']);
                $table->index('fecha');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('movimientos_inventario');
    }
};
