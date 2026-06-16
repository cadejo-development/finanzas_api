<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // Eliminar duplicados existentes antes de crear el índice
        // Conserva el registro con el id más bajo de cada par (receta_id, producto_id)
        DB::connection('compras')->statement("
            DELETE FROM receta_ingredientes
            WHERE id NOT IN (
                SELECT MIN(id)
                FROM receta_ingredientes
                GROUP BY receta_id, COALESCE(producto_id::text, ''), COALESCE(sub_receta_id::text, '')
            )
        ");

        // Índice único parcial: mismo producto no puede aparecer dos veces en la misma receta
        DB::connection('compras')->statement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_receta_ing_producto
            ON receta_ingredientes (receta_id, producto_id)
            WHERE producto_id IS NOT NULL
        ");

        // Índice único parcial: misma sub-receta no puede aparecer dos veces
        DB::connection('compras')->statement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_receta_ing_subreceta
            ON receta_ingredientes (receta_id, sub_receta_id)
            WHERE sub_receta_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::connection('compras')->statement("DROP INDEX IF EXISTS compras.uq_receta_ing_producto");
        DB::connection('compras')->statement("DROP INDEX IF EXISTS compras.uq_receta_ing_subreceta");
    }
};
