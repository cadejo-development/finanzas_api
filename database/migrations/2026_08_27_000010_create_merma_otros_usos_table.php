<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('merma_otros_usos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventario_id')->constrained('merma_inventarios')->cascadeOnDelete();
            $table->foreignId('cerveza_id')->constrained('merma_cervezas');
            $table->decimal('cantidad',     10, 3);
            $table->string('unidad', 5);
            $table->decimal('oz_calculado', 10, 2);
            $table->string('categoria', 100);
            $table->text('detalle')->nullable();
            $table->string('usuario_captura', 150)->nullable();
            $table->timestampTz('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('merma_otros_usos');
    }
};
