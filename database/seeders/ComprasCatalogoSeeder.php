<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra las categorías y productos del catálogo de compras.
 * Conexión: 'compras'
 */
class ComprasCatalogoSeeder extends Seeder
{
    public function run(): void
    {
        $compras = DB::connection('compras');
        $now = now();

        // ── Categorías ────────────────────────────────────────────────────
        $categorias = [
            ['key' => 'general',  'nombre' => 'General',        'orden' => 1],
            ['key' => 'cp',       'nombre' => 'CP Terminado',   'orden' => 2],
            ['key' => 'empaque',  'nombre' => 'Empaque',        'orden' => 3],
            ['key' => 'promo',    'nombre' => 'Promocionales',  'orden' => 4],
            ['key' => 'extras',   'nombre' => 'Extras',         'orden' => 5],
        ];

        foreach ($categorias as $cat) {
            $compras->table('categorias')->updateOrInsert(
                ['key' => $cat['key']],
                array_merge($cat, [
                    'activo'      => true,
                    'aud_usuario' => 'seed',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ])
            );
        }

        // Obtener IDs de categorías
        $catIds = $compras->table('categorias')->pluck('id', 'key');

        // ── Productos ─────────────────────────────────────────────────────
        $productos = [
            // GENERAL
            ['categoria_key' => 'general', 'codigo' => 'A001', 'nombre' => 'Arroz',          'unidad' => 'kg',  'precio' => 25.50],
            ['categoria_key' => 'general', 'codigo' => 'A002', 'nombre' => 'Frijol',         'unidad' => 'kg',  'precio' => 30.00],
            ['categoria_key' => 'general', 'codigo' => 'A003', 'nombre' => 'Aceite',         'unidad' => 'lt',  'precio' => 45.00],
            ['categoria_key' => 'general', 'codigo' => 'A004', 'nombre' => 'Sal',            'unidad' => 'kg',  'precio' =>  8.00],
            ['categoria_key' => 'general', 'codigo' => 'A005', 'nombre' => 'Azucar',         'unidad' => 'kg',  'precio' => 12.00],
            ['categoria_key' => 'general', 'codigo' => 'A006', 'nombre' => 'Carne de res',   'unidad' => 'lb',  'precio' => 55.00],
            ['categoria_key' => 'general', 'codigo' => 'A007', 'nombre' => 'Pollo entero',   'unidad' => 'lb',  'precio' => 28.00],
            ['categoria_key' => 'general', 'codigo' => 'A008', 'nombre' => 'Tomate',         'unidad' => 'lb',  'precio' =>  9.00],
            ['categoria_key' => 'general', 'codigo' => 'A009', 'nombre' => 'Cebolla',        'unidad' => 'lb',  'precio' =>  7.00],
            ['categoria_key' => 'general', 'codigo' => 'A010', 'nombre' => 'Papa',           'unidad' => 'lb',  'precio' =>  6.50],
            ['categoria_key' => 'general', 'codigo' => 'A011', 'nombre' => 'Chile pimiento', 'unidad' => 'lb',  'precio' => 11.00],
            ['categoria_key' => 'general', 'codigo' => 'A012', 'nombre' => 'Ajos',           'unidad' => 'lb',  'precio' => 20.00],
            ['categoria_key' => 'general', 'codigo' => 'A013', 'nombre' => 'Limon',          'unidad' => 'lb',  'precio' =>  8.00],
            ['categoria_key' => 'general', 'codigo' => 'A014', 'nombre' => 'Crema',          'unidad' => 'lt',  'precio' => 22.00],
            ['categoria_key' => 'general', 'codigo' => 'A015', 'nombre' => 'Leche',          'unidad' => 'lt',  'precio' => 14.00],

            // CP TERMINADO
            ['categoria_key' => 'cp', 'codigo' => 'CP01', 'nombre' => 'Pollo rostizado',    'unidad' => 'pz',  'precio' => 120.00],
            ['categoria_key' => 'cp', 'codigo' => 'CP02', 'nombre' => 'Ensalada Cesar',     'unidad' => 'pz',  'precio' =>  60.00],
            ['categoria_key' => 'cp', 'codigo' => 'CP03', 'nombre' => 'Carne asada',        'unidad' => 'pz',  'precio' => 150.00],
            ['categoria_key' => 'cp', 'codigo' => 'CP04', 'nombre' => 'Plato tipico',       'unidad' => 'pz',  'precio' =>  75.00],
            ['categoria_key' => 'cp', 'codigo' => 'CP05', 'nombre' => 'Costillas BBQ',      'unidad' => 'pz',  'precio' => 180.00],
            ['categoria_key' => 'cp', 'codigo' => 'CP06', 'nombre' => 'Nachos especiales',  'unidad' => 'pz',  'precio' =>  55.00],
            ['categoria_key' => 'cp', 'codigo' => 'CP07', 'nombre' => 'Alitas picantes',    'unidad' => 'pz',  'precio' =>  85.00],
            ['categoria_key' => 'cp', 'codigo' => 'CP08', 'nombre' => 'Tabla de quesos',    'unidad' => 'pz',  'precio' =>  95.00],
            ['categoria_key' => 'cp', 'codigo' => 'CP09', 'nombre' => 'Sopa de mariscos',   'unidad' => 'pz',  'precio' => 110.00],
            ['categoria_key' => 'cp', 'codigo' => 'CP10', 'nombre' => 'Plato bajo en cal',  'unidad' => 'pz',  'precio' =>  65.00],
            ['categoria_key' => 'cp', 'codigo' => 'CP11', 'nombre' => 'Churrasco',          'unidad' => 'pz',  'precio' => 200.00],
            ['categoria_key' => 'cp', 'codigo' => 'CP12', 'nombre' => 'Filete de pescado',  'unidad' => 'pz',  'precio' => 140.00],

            // EMPAQUE
            ['categoria_key' => 'empaque', 'codigo' => 'E01', 'nombre' => 'Caja carton',    'unidad' => 'pz',  'precio' =>  5.00],
            ['categoria_key' => 'empaque', 'codigo' => 'E02', 'nombre' => 'Bolsa plastica', 'unidad' => 'pz',  'precio' =>  0.80],
            ['categoria_key' => 'empaque', 'codigo' => 'E03', 'nombre' => 'Recipiente plastico', 'unidad' => 'pz', 'precio' => 3.50],
            ['categoria_key' => 'empaque', 'codigo' => 'E04', 'nombre' => 'Tapa de recipiente',  'unidad' => 'pz', 'precio' => 1.50],
            ['categoria_key' => 'empaque', 'codigo' => 'E05', 'nombre' => 'Tenedor plastico', 'unidad' => 'pz', 'precio' => 0.50],
            ['categoria_key' => 'empaque', 'codigo' => 'E06', 'nombre' => 'Cuchillo plastico','unidad' => 'pz', 'precio' => 0.50],
            ['categoria_key' => 'empaque', 'codigo' => 'E07', 'nombre' => 'Pajillas',         'unidad' => 'pz', 'precio' => 0.20],

            // PROMOCIONALES
            ['categoria_key' => 'promo', 'codigo' => 'P01', 'nombre' => 'Volante',           'unidad' => 'pz',  'precio' =>  1.50],
            ['categoria_key' => 'promo', 'codigo' => 'P02', 'nombre' => 'Cuponera',          'unidad' => 'pz',  'precio' =>  2.00],
            ['categoria_key' => 'promo', 'codigo' => 'P03', 'nombre' => 'Banner impreso',    'unidad' => 'pz',  'precio' => 80.00],
            ['categoria_key' => 'promo', 'codigo' => 'P04', 'nombre' => 'Sticker de marca',  'unidad' => 'pz',  'precio' =>  0.75],

            // EXTRAS
            ['categoria_key' => 'extras', 'codigo' => 'X01', 'nombre' => 'Servilleta',      'unidad' => 'pz',  'precio' =>  0.20],
            ['categoria_key' => 'extras', 'codigo' => 'X02', 'nombre' => 'Encendedor',      'unidad' => 'pz',  'precio' => 10.00],
            ['categoria_key' => 'extras', 'codigo' => 'X03', 'nombre' => 'Vela decorativa', 'unidad' => 'pz',  'precio' => 15.00],
            ['categoria_key' => 'extras', 'codigo' => 'X04', 'nombre' => 'Palillo de dientes','unidad' => 'pz', 'precio' =>  0.10],
        ];

        foreach ($productos as $prod) {
            $catId = $catIds[$prod['categoria_key']] ?? null;
            if (!$catId) continue;

            $compras->table('productos')->updateOrInsert(
                ['codigo' => $prod['codigo']],
                [
                    'categoria_id' => $catId,
                    'nombre'       => $prod['nombre'],
                    'unidad'       => $prod['unidad'],
                    'precio'       => $prod['precio'],
                    'activo'       => true,
                    'aud_usuario'  => 'seed',
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]
            );
        }

        $this->command->info('Catalogo de compras (categorias + productos) generado correctamente.');
    }
}
