<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $cat = \App\Models\Categoria::pluck('id', 'key');
        \App\Models\Producto::insert([
            // GENERAL
            [
                'categoria_id' => $cat['general'],
                'codigo' => 'A001',
                'nombre' => 'Arroz',
                'unidad' => 'kg',
                'precio' => 1.25,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'categoria_id' => $cat['general'],
                'codigo' => 'A002',
                'nombre' => 'Frijol',
                'unidad' => 'kg',
                'precio' => 1.10,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'categoria_id' => $cat['general'],
                'codigo' => 'A003',
                'nombre' => 'Aceite',
                'unidad' => 'lt',
                'precio' => 2.50,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // CP TERMINADO
            [
                'categoria_id' => $cat['cp'],
                'codigo' => 'CP01',
                'nombre' => 'Pollo rostizado',
                'unidad' => 'pz',
                'precio' => 5.00,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'categoria_id' => $cat['cp'],
                'codigo' => 'CP02',
                'nombre' => 'Ensalada César',
                'unidad' => 'pz',
                'precio' => 3.50,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // EMPAQUE
            [
                'categoria_id' => $cat['empaque'],
                'codigo' => 'E01',
                'nombre' => 'Caja cartón',
                'unidad' => 'pz',
                'precio' => 0.80,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'categoria_id' => $cat['empaque'],
                'codigo' => 'E02',
                'nombre' => 'Bolsa plástica',
                'unidad' => 'pz',
                'precio' => 0.10,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // PROMO
            [
                'categoria_id' => $cat['promo'],
                'codigo' => 'P01',
                'nombre' => 'Volante',
                'unidad' => 'pz',
                'precio' => 0.05,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'categoria_id' => $cat['promo'],
                'codigo' => 'P02',
                'nombre' => 'Cuponera',
                'unidad' => 'pz',
                'precio' => 0.15,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // EXTRAS
            [
                'categoria_id' => $cat['extras'],
                'codigo' => 'X01',
                'nombre' => 'Servilleta',
                'unidad' => 'pz',
                'precio' => 0.02,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'categoria_id' => $cat['extras'],
                'codigo' => 'X02',
                'nombre' => 'Encendedor',
                'unidad' => 'pz',
                'precio' => 0.50,
                'activo' => true,
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
