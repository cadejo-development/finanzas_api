<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea tablas de recetas e ingredientes en la conexión 'compras'.
 *
 * recetas            – cabecera de la receta (nombre, tipo, platos base)
 * receta_ingredientes – linea: producto + cantidad_por_plato + unidad
 *
 * FK: receta_ingredientes.receta_id  → recetas.id
 *     receta_ingredientes.producto_id → productos.id
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Recetas ───────────────────────────────────────────────────────
        if (!Schema::connection('compras')->hasTable('recetas')) {
            Schema::connection('compras')->create('recetas', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 150);
                $table->text('descripcion')->nullable();
                $table->string('tipo', 80)->nullable();   // tipo/categoria libre (ej: Cocina, Barra)
                $table->integer('platos_semana')->default(0);
                $table->boolean('activa')->default(true);
                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();
            });
        }

        // ── Ingredientes de receta ─────────────────────────────────────────
        if (!Schema::connection('compras')->hasTable('receta_ingredientes')) {
            Schema::connection('compras')->create('receta_ingredientes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('receta_id')
                      ->constrained('recetas')
                      ->onDelete('cascade');
                $table->foreignId('producto_id')
                      ->constrained('productos')
                      ->onDelete('restrict');
                $table->decimal('cantidad_por_plato', 12, 4)->default(0);
                $table->string('unidad', 20)->default('u');  // kg, oz, lt, u, etc.
                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();

                $table->index('receta_id');
                $table->index('producto_id');
                $table->unique(['receta_id', 'producto_id'], 'ri_receta_producto_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('receta_ingredientes');
        Schema::connection('compras')->dropIfExists('recetas');
    }
};
