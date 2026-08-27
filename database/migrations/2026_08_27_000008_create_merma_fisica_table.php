<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('merma_fisica', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventario_id')->unique()->constrained('merma_inventarios')->cascadeOnDelete();
            $table->decimal('cantidad',     10, 3)->default(0);
            $table->string('unidad', 5)->default('L'); // L / ml / oz
            $table->decimal('oz_calculado', 10, 2)->default(0);
            $table->boolean('confirmada')->default(false);
            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('merma_fisica');
    }
};
