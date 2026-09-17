<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('compras')
            ->table('productos')
            ->where('codigo', 'PT03260800')
            ->update(['excluir_estadisticas' => true]);
    }

    public function down(): void
    {
        DB::connection('compras')
            ->table('productos')
            ->where('codigo', 'PT03260800')
            ->update(['excluir_estadisticas' => false]);
    }
};
