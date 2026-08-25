<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // ¿auditoria_items tiene columna receta_item_id?
        $cols = DB::connection('compras')
            ->select("SELECT column_name FROM information_schema.columns WHERE table_name = 'auditoria_items' AND table_schema = 'public' ORDER BY ordinal_position");
        dump("Columnas de auditoria_items:");
        dump(array_column($cols, 'column_name'));

        // Criterios en auditoria_items para auditoria_id=101
        $items101 = DB::connection('compras')
            ->table('auditoria_items')
            ->where('auditoria_id', 101)
            ->limit(5)
            ->get();
        dump("auditoria_items para auditoria_id=101: " . $items101->count());
        if ($items101->count()) dump($items101->first());

        // Criterios con receta_item_id=3
        $hasCol = collect($cols)->contains(fn($c) => $c->column_name === 'receta_item_id');
        if ($hasCol) {
            $ri3 = DB::connection('compras')
                ->table('auditoria_items')
                ->where('receta_item_id', 3)
                ->count();
            dump("auditoria_items con receta_item_id=3: $ri3");
        } else {
            dump("La columna receta_item_id NO existe en auditoria_items");
        }

        // Chequear auditoria_receta_criterios para items 3,4,5,6
        $crit = DB::connection('compras')
            ->table('auditoria_receta_criterios')
            ->whereIn('receta_item_id', [3,4,5,6])
            ->count();
        dump("auditoria_receta_criterios para items 3-6: $crit");
    }

    public function down(): void {}
};
