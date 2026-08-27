<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('merma_inv_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventario_id')->constrained('merma_inventarios')->cascadeOnDelete();
            $table->foreignId('cerveza_id')->constrained('merma_cervezas');
            $table->decimal('inicial_oz',       10, 2)->default(0);
            $table->unsignedSmallInteger('final_cerrados_p')->default(0);
            $table->unsignedSmallInteger('final_cerrados_g')->default(0);

            $table->unique(['inventario_id', 'cerveza_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('merma_inv_items');
    }
};
