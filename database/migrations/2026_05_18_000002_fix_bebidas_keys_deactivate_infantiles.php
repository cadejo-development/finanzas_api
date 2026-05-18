<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rc  = fn () => DB::connection('compras')->table('receta_categorias');
        $now = now();

        // Limpiar los tres keys conflictivos antes de reasignar (evita violación de unique)
        $rc()->whereIn('nombre', ['Bebidas con Alcohol', 'Bebidas sin Alcohol', 'Bebidas Malcriadas AE2 s/a'])
             ->update(['key' => null, 'updated_at' => $now]);

        // Asignar los keys correctos según Brilo (olComun.dbo.CategoriasProductos)
        $rc()->where('nombre', 'Bebidas con Alcohol')      ->update(['key' => 'BR-02', 'updated_at' => $now]);
        $rc()->where('nombre', 'Bebidas sin Alcohol')      ->update(['key' => 'BR-01', 'updated_at' => $now]);
        $rc()->where('nombre', 'Bebidas Malcriadas AE2 s/a')->update(['key' => 'BR-05', 'updated_at' => $now]);

        // Desactivar Platos Infantiles (no existe en Brilo)
        $rc()->where('nombre', 'Platos Infantiles')->update(['activa' => false, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $rc  = fn () => DB::connection('compras')->table('receta_categorias');
        $now = now();

        $rc()->whereIn('nombre', ['Bebidas con Alcohol', 'Bebidas sin Alcohol', 'Bebidas Malcriadas AE2 s/a'])
             ->update(['key' => null, 'updated_at' => $now]);

        $rc()->where('nombre', 'Bebidas con Alcohol')      ->update(['key' => 'BR-01', 'updated_at' => $now]);
        $rc()->where('nombre', 'Bebidas sin Alcohol')      ->update(['key' => 'BR-05', 'updated_at' => $now]);
        $rc()->where('nombre', 'Bebidas Malcriadas AE2 s/a')->update(['key' => 'BR-06', 'updated_at' => $now]);

        $rc()->where('nombre', 'Platos Infantiles')->update(['activa' => true, 'updated_at' => $now]);
    }
};
