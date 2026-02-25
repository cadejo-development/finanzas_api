<?php

namespace Database\Seeders;

use App\Models\Proveedor;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProveedoresSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Proveedor::on('pagos')->insert([
            [
                'nombre' => 'Proveedor Genérico S.A. de C.V.',
                'nit' => '0614-010101-001-1',
                'nrc' => '123456-7',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Servicios Profesionales El Salvador',
                'nit' => '0614-020202-002-2',
                'nrc' => '765432-1',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Distribuidora Comercial XYZ',
                'nit' => '0614-030303-003-3',
                'nrc' => '246810-5',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
