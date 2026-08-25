<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        DB::connection('compras')
            ->table('auditorias_receta')
            ->whereNull('receta_id')
            ->update(['es_multi_receta' => true]);
    }

    public function down(): void {}
};
