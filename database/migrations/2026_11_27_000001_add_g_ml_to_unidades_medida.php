<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function connection(): string { return 'compras'; }

    public function up(): void
    {
        $maxOrden = DB::connection('compras')->table('unidades_medida')->max('orden') ?? 20;

        $nuevas = [
            ['codigo' => 'g',  'nombre' => 'Gramo',      'activo' => true, 'orden' => $maxOrden + 1],
            ['codigo' => 'ml', 'nombre' => 'Mililitro',  'activo' => true, 'orden' => $maxOrden + 2],
        ];

        foreach ($nuevas as $u) {
            DB::connection('compras')
                ->table('unidades_medida')
                ->insertOrIgnore($u);
        }
    }

    public function down(): void
    {
        DB::connection('compras')
            ->table('unidades_medida')
            ->whereIn('codigo', ['g', 'ml'])
            ->delete();
    }
};
