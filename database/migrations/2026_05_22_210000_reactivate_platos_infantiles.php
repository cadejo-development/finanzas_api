<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('compras')
            ->table('receta_categorias')
            ->where('nombre', 'Platos Infantiles')
            ->update(['activa' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::connection('compras')
            ->table('receta_categorias')
            ->where('nombre', 'Platos Infantiles')
            ->update(['activa' => false, 'updated_at' => now()]);
    }
};
