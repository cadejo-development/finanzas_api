<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('merma_entradas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventario_id')->constrained('merma_inventarios')->cascadeOnDelete();
            $table->foreignId('cerveza_id')->constrained('merma_cervezas');
            $table->string('tamanio', 1); // p / g
            $table->unsignedSmallInteger('cantidad')->default(1);
            $table->time('hora_ingreso')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('merma_entradas');
    }
};
