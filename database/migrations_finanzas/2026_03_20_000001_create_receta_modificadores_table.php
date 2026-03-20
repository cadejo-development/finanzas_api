<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de modificadores de receta.
 *
 * Un modificador es una variante de ingrediente que el POS permite cambiar
 * al tomar la orden (ej: tipo de pan, salsa alternativa, etc.).
 *
 * Estructura (plana, agrupada por grupo_id_origen en la app):
 *  - grupo_*   : el GRUPO de modificadores (ej: "Tipo de pan")
 *  - opcion_*  : la OPCION dentro del grupo (ej: "Pan integral")
 *  - producto_* : el ingrediente asociado a esa opción
 */
return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('receta_modificadores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('receta_id')
                  ->constrained('recetas')
                  ->cascadeOnDelete();

            // Grupo de modificadores (padre en ModificadoresRst)
            $table->unsignedInteger('grupo_id_origen');
            $table->string('grupo_codigo', 30)->nullable();
            $table->string('grupo_nombre', 150);

            // Opción dentro del grupo (hijo en ModificadoresRst)
            $table->string('opcion_nombre', 150);

            // Producto/ingrediente asociado a la opción
            $table->foreignId('producto_id')
                  ->nullable()
                  ->constrained('productos')
                  ->nullOnDelete();

            $table->decimal('cantidad', 10, 4)->default(0);
            $table->string('unidad', 20)->default('u');

            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();

            $table->index(['receta_id', 'grupo_id_origen']);
            $table->unique(
                ['receta_id', 'grupo_id_origen', 'opcion_nombre'],
                'rm_receta_grupo_opcion_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('receta_modificadores');
    }
};
