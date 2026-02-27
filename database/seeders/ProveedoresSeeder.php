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
        Proveedor::insert([
            [
                'codigo' => 'PRV-001',
                'nombre' => 'Proveedor Genérico S.A. de C.V.',
                'nit' => '0614-010101-001-1',
                'nrc' => '123456-7',
                'telefono' => '2222-1111',
                'direccion' => 'Calle Falsa 123',
                'cuenta_bancaria' => '000-123456',
                'tipo_cuenta' => 'Ahorro',
                'banco' => 'Banco Dummy',
                'correo' => 'proveedor@generico.com',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'PRV-002',
                'nombre' => 'Servicios Profesionales El Salvador',
                'nit' => '0614-020202-002-2',
                'nrc' => '765432-1',
                'telefono' => '2333-2222',
                'direccion' => 'Av. Central 456',
                'cuenta_bancaria' => '000-654321',
                'tipo_cuenta' => 'Corriente',
                'banco' => 'Banco Ficticio',
                'correo' => 'contacto@serviciospro.com',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'PRV-003',
                'nombre' => 'Distribuidora Comercial XYZ',
                'nit' => '0614-030303-003-3',
                'nrc' => '246810-5',
                'telefono' => '2444-3333',
                'direccion' => 'Boulevard Empresarial 789',
                'cuenta_bancaria' => '000-789123',
                'tipo_cuenta' => 'Ahorro',
                'banco' => 'Banco Ejemplo',
                'correo' => 'ventas@xyz.com',
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
