<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('compras')->create('brilo_snapshot_recetas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('nombre', 200);
            $table->string('tipo_receta', 30)->nullable();          // plato | sub_receta
            $table->string('categoria_codigo', 50)->nullable();
            $table->string('categoria_nombre', 100)->nullable();
            $table->decimal('precio', 10, 4)->nullable();
            $table->boolean('activo')->default(true);
            $table->boolean('no_enviar_cocina')->default(false);    // proNoEnviarACocinaRST
            $table->boolean('en_boton_cocina')->default(false);     // tiene filas en ProductoXCocinaXSucRst
            $table->integer('pro_id')->nullable();                  // Brilo proId
            $table->jsonb('sucursales')->nullable();                // [{sucId, ccirstId}]
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('compras')->create('brilo_snapshot_ingredientes', function (Blueprint $table) {
            $table->id();
            $table->string('receta_codigo', 50)->index();
            $table->string('ingrediente_codigo', 50);
            $table->string('ingrediente_nombre', 200)->nullable();
            $table->boolean('es_sub_receta')->default(false);
            $table->decimal('cantidad_base', 14, 6)->nullable();
            $table->string('unidad_base', 50)->nullable();
            $table->decimal('cantidad_pres', 14, 6)->nullable();
            $table->string('unidad_pres', 50)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['receta_codigo', 'ingrediente_codigo'], 'uq_brilo_snap_ing');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('brilo_snapshot_ingredientes');
        Schema::connection('compras')->dropIfExists('brilo_snapshot_recetas');
    }
};
