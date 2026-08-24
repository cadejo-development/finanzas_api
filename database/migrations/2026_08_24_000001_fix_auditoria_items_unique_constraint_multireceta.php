<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection(): string { return 'compras'; }

    public function up(): void
    {
        // El constraint original (auditoria_id, criterio_id) impide guardar criterios
        // por separado para cada receta en auditorías multi-receta, ya que el mismo
        // criterio_id aparece en todas las recetas. Se reemplaza por un índice que
        // incluye receta_item_id con NULLS NOT DISTINCT (PG 15+) para que:
        //   - formato antiguo (receta_item_id IS NULL): sigue siendo único por (auditoria_id, criterio_id)
        //   - formato multi-receta: único por (auditoria_id, criterio_id, receta_item_id)
        DB::connection('compras')->statement('
            ALTER TABLE auditoria_items
            DROP CONSTRAINT IF EXISTS auditoria_items_auditoria_id_criterio_id_key
        ');

        DB::connection('compras')->statement('
            CREATE UNIQUE INDEX auditoria_items_unique_per_receta
            ON auditoria_items (auditoria_id, criterio_id, receta_item_id)
            NULLS NOT DISTINCT
        ');
    }

    public function down(): void
    {
        DB::connection('compras')->statement('
            DROP INDEX IF EXISTS auditoria_items_unique_per_receta
        ');

        DB::connection('compras')->statement('
            ALTER TABLE auditoria_items
            ADD CONSTRAINT auditoria_items_auditoria_id_criterio_id_key
            UNIQUE (auditoria_id, criterio_id)
        ');
    }
};
