<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla pivote: platos_semana que cada sucursal planifica para una receta.
 *
 * Los ingredientes de la receta son universales; solo la cantidad de platos
 * planeados por semana varía entre sucursales.
 *
 * sucursal_id referencia public.sucursales en la conexión "pgsql", pero como
 * son bases de datos distintas no se establece FK cruzada; la integridad
 * se maneja a nivel de aplicación.
 */
return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('receta_sucursal', function (Blueprint $table) {
            $table->id();

            $table->foreignId('receta_id')
                  ->constrained('recetas')
                  ->cascadeOnDelete();

            // Sin FK real (BD distinta). La app garantiza que sea un id válido de sucursales.
            $table->unsignedInteger('sucursal_id');

            $table->unsignedInteger('platos_semana')->default(0);
            $table->boolean('activa')->default(true);
            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();

            $table->unique(['receta_id', 'sucursal_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('receta_sucursal');
    }
};
