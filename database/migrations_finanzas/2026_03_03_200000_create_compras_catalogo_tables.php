<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea tablas de catálogo (categorias + productos) en la conexión 'compras'.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Categorías de productos ────────────────────────────────────────
        if (!Schema::connection('compras')->hasTable('categorias')) {
            Schema::connection('compras')->create('categorias', function (Blueprint $table) {
                $table->id();
                $table->string('key', 30)->unique();
                $table->string('nombre', 80);
                $table->integer('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();
            });
        }

        // ── Productos ─────────────────────────────────────────────────────
        if (!Schema::connection('compras')->hasTable('productos')) {
            Schema::connection('compras')->create('productos', function (Blueprint $table) {
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
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('productos');
        Schema::connection('compras')->dropIfExists('categorias');
    }
};
