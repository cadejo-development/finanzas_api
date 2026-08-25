<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // Backfill receta_item_id a partir del formato descripcion = 'ri:{itemId}:sec:{categoria}'
        // Filas con este prefijo y receta_item_id NULL quedaron así por bug en $fillable del modelo.
        DB::connection('compras')->statement("
            UPDATE auditoria_fotos
            SET receta_item_id = CAST(SPLIT_PART(descripcion, ':', 2) AS bigint)
            WHERE descripcion LIKE 'ri:%:sec:%'
              AND receta_item_id IS NULL
        ");
    }

    public function down(): void {}
};
