<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        if (!Schema::connection('compras')->hasTable('inventarios')) {
            Schema::connection('compras')->create('inventarios', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sucursal_id');
                $table->unsignedBigInteger('producto_id');

                // Cantidad del conteo físico (cargada desde Excel o manual)
                $table->decimal('cantidad_inicial', 12, 4)->default(0);
                // Unidad en que se expresó el conteo (kg, lb, oz, u, etc.)
                $table->string('unidad', 30);
                // Cantidad inicial normalizada a unidad_base del producto
                $table->decimal('cantidad_inicial_base', 14, 6)->default(0);

                $table->date('fecha_conteo');

                // Umbral mínimo de alerta (en la misma unidad que cantidad_inicial)
                $table->decimal('stock_minimo', 12, 4)->nullable();

                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();

                $table->unique(['sucursal_id', 'producto_id'], 'inv_sucursal_producto_unique');
                $table->index('sucursal_id');
                $table->index('producto_id');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('inventarios');
    }
};
