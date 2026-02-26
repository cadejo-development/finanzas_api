<?php

namespace Database\Seeders;

use App\Models\Contribuyente;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ContribuyentesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Contribuyente::insert([
            [
                'codigo' => 'no_inscrito',
                'nombre' => 'No Inscrito / Factura Consumidor',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'otros',
                'nombre' => 'Otros contribuyentes',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'gran_contribuyente',
                'nombre' => 'Gran Contribuyente',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
