<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Categoria;
use App\Models\Producto;

class ProductosSeeder extends Seeder
{
    public function run(): void
    {
        // Truncar usando el modelo (ya trae conexión)
        Producto::truncate();

        // Catálogo de categorías por key => id
        // Usar valores manuales para categoria_id
        Producto::insert([
            // GENERAL
            [
                'categoria_codigo' => 'general',
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
                'categoria_codigo' => 'general',
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
                'categoria_codigo' => 'general',
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
                'categoria_codigo' => 'cp',
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
                'categoria_codigo' => 'cp',
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
                'categoria_codigo' => 'empaque',
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
                'categoria_codigo' => 'empaque',
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
                'categoria_codigo' => 'promo',
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
                'categoria_codigo' => 'promo',
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
                'categoria_codigo' => 'extras',
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
                'categoria_codigo' => 'extras',
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