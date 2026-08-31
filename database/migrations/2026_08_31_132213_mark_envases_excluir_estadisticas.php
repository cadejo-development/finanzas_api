<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // Toda la categoría "Envases" (id=526) se excluye de estadísticas de inventario
        DB::connection('compras')
            ->table('productos')
            ->where('categoria_id', 526)
            ->update(['excluir_estadisticas' => true]);
    }

    public function down(): void
    {
        DB::connection('compras')
            ->table('productos')
            ->where('categoria_id', 526)
            ->update(['excluir_estadisticas' => false]);
    }
};
