<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra recetas de prueba con sus ingredientes para el modulo de compras.
 * Requiere que ComprasCatalogoSeeder ya haya sido ejecutado.
 */
class ComprasRecetasSeeder extends Seeder
{
    public function run(): void
    {
        $compras = DB::connection('compras');
        $now = now();

        // Obtener IDs de productos por codigo
        $prodIds = $compras->table('productos')->pluck('id', 'codigo');

        // Helper para obtener ID o null
        $pid = fn(string $codigo) => $prodIds[$codigo] ?? null;

        // ── Definicion de recetas de prueba ───────────────────────────────
        $recetas = [
            [
                'nombre'        => 'Pollo Asado',
                'descripcion'   => 'Pollo marinado al carbon con guarnicion clasica',
                'tipo'          => 'Cocina',
                'platos_semana' => 50,
                'ingredientes'  => [
                    ['codigo' => 'A007', 'cantidad_por_plato' => 0.50, 'unidad' => 'lb'],  // Pollo entero
                    ['codigo' => 'A003', 'cantidad_por_plato' => 0.02, 'unidad' => 'lt'],  // Aceite
                    ['codigo' => 'A004', 'cantidad_por_plato' => 0.01, 'unidad' => 'kg'],  // Sal
                    ['codigo' => 'A012', 'cantidad_por_plato' => 0.05, 'unidad' => 'lb'],  // Ajos
                    ['codigo' => 'A013', 'cantidad_por_plato' => 0.10, 'unidad' => 'lb'],  // Limon
                    ['codigo' => 'A001', 'cantidad_por_plato' => 0.10, 'unidad' => 'kg'],  // Arroz
                ],
            ],
            [
                'nombre'        => 'Carne Asada con Guarnicion',
                'descripcion'   => 'Carne de res a la plancha con arroz y frijoles',
                'tipo'          => 'Cocina',
                'platos_semana' => 40,
                'ingredientes'  => [
                    ['codigo' => 'A006', 'cantidad_por_plato' => 0.50, 'unidad' => 'lb'],  // Carne de res
                    ['codigo' => 'A001', 'cantidad_por_plato' => 0.10, 'unidad' => 'kg'],  // Arroz
                    ['codigo' => 'A002', 'cantidad_por_plato' => 0.08, 'unidad' => 'kg'],  // Frijol
                    ['codigo' => 'A003', 'cantidad_por_plato' => 0.02, 'unidad' => 'lt'],  // Aceite
                    ['codigo' => 'A009', 'cantidad_por_plato' => 0.10, 'unidad' => 'lb'],  // Cebolla
                    ['codigo' => 'A008', 'cantidad_por_plato' => 0.10, 'unidad' => 'lb'],  // Tomate
                ],
            ],
            [
                'nombre'        => 'Plato Tipico',
                'descripcion'   => 'Arroz, frijoles, pollo y crema — plato estandar de sucursal',
                'tipo'          => 'Cocina',
                'platos_semana' => 60,
                'ingredientes'  => [
                    ['codigo' => 'A001', 'cantidad_por_plato' => 0.10, 'unidad' => 'kg'],  // Arroz
                    ['codigo' => 'A002', 'cantidad_por_plato' => 0.10, 'unidad' => 'kg'],  // Frijol
                    ['codigo' => 'A007', 'cantidad_por_plato' => 0.30, 'unidad' => 'lb'],  // Pollo entero
                    ['codigo' => 'A014', 'cantidad_por_plato' => 0.03, 'unidad' => 'lt'],  // Crema
                    ['codigo' => 'E01',  'cantidad_por_plato' => 1.00, 'unidad' => 'pz'],  // Caja carton
                ],
            ],
            [
                'nombre'        => 'Ensalada Cesar',
                'descripcion'   => 'Ensalada fresca con aderezo cesar',
                'tipo'          => 'Cocina',
                'platos_semana' => 30,
                'ingredientes'  => [
                    ['codigo' => 'A008', 'cantidad_por_plato' => 0.15, 'unidad' => 'lb'],  // Tomate
                    ['codigo' => 'A009', 'cantidad_por_plato' => 0.05, 'unidad' => 'lb'],  // Cebolla
                    ['codigo' => 'A014', 'cantidad_por_plato' => 0.05, 'unidad' => 'lt'],  // Crema
                    ['codigo' => 'A012', 'cantidad_por_plato' => 0.03, 'unidad' => 'lb'],  // Ajos
                    ['codigo' => 'A013', 'cantidad_por_plato' => 0.08, 'unidad' => 'lb'],  // Limon
                ],
            ],
            [
                'nombre'        => 'Nachos con Guacamole',
                'descripcion'   => 'Nachos con toppings de barra: crema, tomate y cebolla',
                'tipo'          => 'Barra',
                'platos_semana' => 25,
                'ingredientes'  => [
                    ['codigo' => 'A008', 'cantidad_por_plato' => 0.10, 'unidad' => 'lb'],  // Tomate
                    ['codigo' => 'A009', 'cantidad_por_plato' => 0.05, 'unidad' => 'lb'],  // Cebolla
                    ['codigo' => 'A014', 'cantidad_por_plato' => 0.05, 'unidad' => 'lt'],  // Crema
                    ['codigo' => 'A013', 'cantidad_por_plato' => 0.05, 'unidad' => 'lb'],  // Limon
                    ['codigo' => 'E03',  'cantidad_por_plato' => 1.00, 'unidad' => 'pz'],  // Recipiente plastico
                ],
            ],
            [
                'nombre'        => 'Costillas BBQ',
                'descripcion'   => 'Costillas de cerdo glaseadas con salsa BBQ casera',
                'tipo'          => 'Cocina',
                'platos_semana' => 20,
                'ingredientes'  => [
                    ['codigo' => 'A006', 'cantidad_por_plato' => 0.60, 'unidad' => 'lb'],  // Carne de res (costilla)
                    ['codigo' => 'A005', 'cantidad_por_plato' => 0.02, 'unidad' => 'kg'],  // Azucar (para glaze)
                    ['codigo' => 'A004', 'cantidad_por_plato' => 0.01, 'unidad' => 'kg'],  // Sal
                    ['codigo' => 'A012', 'cantidad_por_plato' => 0.05, 'unidad' => 'lb'],  // Ajos
                    ['codigo' => 'A001', 'cantidad_por_plato' => 0.10, 'unidad' => 'kg'],  // Arroz
                ],
            ],
        ];

        // ── Insertar recetas ──────────────────────────────────────────────
        foreach ($recetas as $r) {
            // Insertar o actualizar receta (por nombre)
            $existing = $compras->table('recetas')->where('nombre', $r['nombre'])->first();

            if ($existing) {
                $recetaId = $existing->id;
                $compras->table('recetas')->where('id', $recetaId)->update([
                    'descripcion'   => $r['descripcion'],
                    'tipo'          => $r['tipo'],
                    'platos_semana' => $r['platos_semana'],
                    'activa'        => true,
                    'aud_usuario'   => 'seed',
                    'updated_at'    => $now,
                ]);
                // Limpiar ingredientes anteriores
                $compras->table('receta_ingredientes')->where('receta_id', $recetaId)->delete();
            } else {
                $recetaId = $compras->table('recetas')->insertGetId([
                    'nombre'        => $r['nombre'],
                    'descripcion'   => $r['descripcion'],
                    'tipo'          => $r['tipo'],
                    'platos_semana' => $r['platos_semana'],
                    'activa'        => true,
                    'aud_usuario'   => 'seed',
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }

            // Insertar ingredientes
            foreach ($r['ingredientes'] as $ing) {
                $productoId = $pid($ing['codigo']);
                if (!$productoId) {
                    $this->command->warn("  Producto {$ing['codigo']} no encontrado para receta {$r['nombre']}");
                    continue;
                }
                $compras->table('receta_ingredientes')->insert([
                    'receta_id'          => $recetaId,
                    'producto_id'        => $productoId,
                    'cantidad_por_plato' => $ing['cantidad_por_plato'],
                    'unidad'             => $ing['unidad'],
                    'aud_usuario'        => 'seed',
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);
            }
        }

        $this->command->info(count($recetas) . ' recetas de prueba generadas correctamente.');
    }
}
