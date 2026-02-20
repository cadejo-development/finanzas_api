<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->constrained('categorias');
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 150);
            $table->string('unidad', 20);
            $table->decimal('precio', 12, 2)->default(0);
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 150)->nullable();
            $table->timestamps();
            $table->index('categoria_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
