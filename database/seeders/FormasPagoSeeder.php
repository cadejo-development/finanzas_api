<?php

namespace Database\Seeders;

use App\Models\FormaPago;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FormasPagoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        FormaPago::insert([
            [
                'codigo' => 'transferencia',
                'nombre' => 'Transferencia Bancaria',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'cheque',
                'nombre' => 'Cheque',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'tarjeta_credito',
                'nombre' => 'Tarjeta de Crédito',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
