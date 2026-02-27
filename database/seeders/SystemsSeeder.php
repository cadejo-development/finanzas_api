<?php

namespace Database\Seeders;

use App\Models\System;
use Illuminate\Database\Seeder;

class SystemsSeeder extends Seeder
{
    public function run(): void
    {
        System::insert([
            [
                'nombre' => 'Pagos',
                'codigo' => 'pagos',
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Compras',
                'codigo' => 'compras',
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
