<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        $now = now();
        $aditivos = [
            ['codigo' => 'ESP-NARANJA',    'nombre' => 'Cáscara de Naranja'],
            ['codigo' => 'ESP-CULANTRO',   'nombre' => 'Culantro / Coriander'],
            ['codigo' => 'ESP-MANZANILLA', 'nombre' => 'Manzanilla (Chamomile)'],
            ['codigo' => 'ESP-JENGIBRE',   'nombre' => 'Jengibre'],
            ['codigo' => 'ESP-NUEZ',       'nombre' => 'Nuez Moscada'],
            ['codigo' => 'ESP-KICK',       'nombre' => 'Kick / Irish Moss (Carragenina)'],
            ['codigo' => 'ESP-MIX',        'nombre' => 'Especias (mix)'],
        ];

        foreach ($aditivos as $aditivo) {
            DB::connection('compras')
                ->table('brew_ingredientes')
                ->insertOrIgnore([
                    'tipo'       => 'aditivo',
                    'codigo'     => $aditivo['codigo'],
                    'nombre'     => $aditivo['nombre'],
                    'activo'     => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        DB::connection('compras')
            ->table('brew_ingredientes')
            ->where('tipo', 'aditivo')
            ->whereIn('codigo', [
                'ESP-NARANJA', 'ESP-CULANTRO', 'ESP-MANZANILLA',
                'ESP-JENGIBRE', 'ESP-NUEZ', 'ESP-KICK', 'ESP-MIX',
            ])
            ->delete();
    }
};
