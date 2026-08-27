<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('merma_ventas_brilo', function (Blueprint $table) {
            $table->id();
            // inventario_id se asigna cuando se cruza con un inventario aprobado/activo
            $table->unsignedBigInteger('inventario_id')->nullable();
            $table->unsignedBigInteger('cerveza_id')->nullable();
            $table->unsignedBigInteger('presentacion_id')->nullable();
            $table->string('presentacion_brilo', 80);   // nombre tal como viene de Brilo
            $table->string('cerveza_brilo',      150);  // nombre tal como viene de Brilo
            $table->integer('cantidad');
            $table->decimal('oz_efectivas', 10, 2);
            $table->date('fecha');
            $table->unsignedInteger('suc_id_brilo');
            $table->timestampTz('synced_at')->nullable();

            // Idempotente: un registro por fecha/sucursal/presentación/cerveza Brilo
            $table->unique(['fecha', 'suc_id_brilo', 'presentacion_brilo', 'cerveza_brilo'], 'merma_vb_unique');
            $table->index('fecha');
            $table->index('suc_id_brilo');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('merma_ventas_brilo');
    }
};
