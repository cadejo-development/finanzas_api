<?php

namespace Database\Seeders;

use App\Models\System;
use Illuminate\Database\Seeder;

class SystemsSeeder extends Seeder
{
    public function run(): void
    {
        $sistemas = [
            ['nombre' => 'Pagos',   'codigo' => 'pagos'],
            ['nombre' => 'Compras', 'codigo' => 'compras'],
        ];

        foreach ($sistemas as $s) {
            System::updateOrCreate(
                ['codigo' => $s['codigo']],
                array_merge($s, ['aud_usuario' => 'seed', 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
