<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BrewCervezasSeeder extends Seeder
{
    public function run(): void
    {
        $cervezas = [
            'Cadejo Roja',
            'Cadejo Negra',
            'Cadejo Mera Belga',
            'Cadejo Hija de Pooh',
            'Cadejo Koloscha',
            'Cadejo Suegra',
            'Cadejo Wapa',
            'Cadejo La Nacional',
            'Cadejo Siguanator',
            'Cadejo Lupe Reyes',
            'Cadejo Oktoberfest',
            'Cadejo Belgadora',
            'Cadejo La Calabaza',
            'Cadejo Skyhopper',
        ];

        DB::connection('compras')->table('brew_ingredientes')
            ->where('tipo', 'cerveza')
            ->delete();

        $now = now();
        foreach ($cervezas as $nombre) {
            DB::connection('compras')->table('brew_ingredientes')->insert([
                'tipo'       => 'cerveza',
                'codigo'     => '',
                'nombre'     => $nombre,
                'activo'     => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $count = DB::connection('compras')->table('brew_ingredientes')
            ->where('tipo', 'cerveza')
            ->count();

        $this->command->info("Cervezas insertadas: $count");
    }
}
