<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('merma_barriles_conectados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('merma_inv_items')->cascadeOnDelete();
            $table->string('tamanio', 1); // p / g
            $table->decimal('peso_lb', 8, 2);
            // oz se calcula en tiempo real: no se persiste
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('merma_barriles_conectados');
    }
};
