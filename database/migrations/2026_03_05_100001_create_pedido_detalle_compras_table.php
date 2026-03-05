<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        if (!Schema::connection('compras')->hasTable('pedido_detalle')) {
            Schema::connection('compras')->create('pedido_detalle', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pedido_id')->constrained('pedidos')->onDelete('cascade');
                $table->unsignedBigInteger('producto_id');          // ref a productos en compras (misma DB)
                $table->decimal('cantidad', 12, 2)->default(0);
                $table->text('nota')->nullable();
                $table->decimal('precio_unitario', 12, 2)->default(0);
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();

                $table->unique(['pedido_id', 'producto_id']);
                $table->index(['pedido_id', 'producto_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('pedido_detalle');
    }
};
