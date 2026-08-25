<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // Criterios para item 3
        $criterios = DB::connection('compras')
            ->table('auditoria_receta_criterios')
            ->where('receta_item_id', 3)
            ->get();
        dump("auditoria_receta_criterios para item_id=3: " . $criterios->count());

        // receta_nombre actual de los items de auditoria 101
        $items = DB::connection('compras')
            ->table('auditoria_receta_items')
            ->where('auditoria_id', 101)
            ->select('id', 'receta_id', 'receta_nombre', 'calificacion')
            ->get();
        dump("Items con receta_nombre:");
        dump($items->toArray());

        // Nombres de las recetas 43997, 43919, 44097, 44093
        $recetas = DB::connection('compras')
            ->table('recetas')
            ->whereIn('id', [43997, 43919, 44097, 44093])
            ->select('id', 'nombre', 'codigo_origen')
            ->get();
        dump("Recetas:");
        dump($recetas->toArray());
    }

    public function down(): void {}
};
