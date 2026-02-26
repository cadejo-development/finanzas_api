<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Sucursal;
use App\Models\CentroCosto;
use App\Models\Producto;
use App\Models\Pedido;
use App\Models\PedidoDetalle;

class PedidosSeeder extends Seeder
{
    public function run(): void
    {
        // Importante: primero detalle y luego encabezado (por FK)
        DB::connection('pagos')->statement('TRUNCATE TABLE pedido_detalle RESTART IDENTITY CASCADE');
        DB::connection('pagos')->statement('TRUNCATE TABLE pedidos RESTART IDENTITY CASCADE');

        // Si estas tablas están también en la conexión "pagos", poneles on('pagos')
        $sucursales = Sucursal::pluck('codigo', 'nombre');      // ['Guírola' => 'SUC001', ...]
        $centros    = CentroCosto::pluck('codigo', 'nombre');   // ['Centro Costo Guírola' => 'CECO...', ...]

        $productos = Producto::get()->keyBy('codigo');          // ['A001' => Producto, ...]

        // Pedido 1: Guírola, BORRADOR
        $pedido1 = Pedido::create([
            'sucursal_codigo' => $sucursales['Guírola'],
            'centro_costo_codigo' => $centros['Centro Costo Guírola'],
            'semana_inicio' => now()->startOfWeek(),
            'semana_fin' => now()->endOfWeek(),
            'estado' => 'BORRADOR',
            'total_estimado' => 0,
            'aud_usuario' => 'seed',
        ]);

        $items1 = [
            ['codigo' => 'A001', 'cantidad' => 10],
            ['codigo' => 'CP01', 'cantidad' => 2],
            ['codigo' => 'E01', 'cantidad' => 5],
        ];

        $total1 = 0;
        foreach ($items1 as $item) {
            if (!isset($productos[$item['codigo']])) {
                continue; // o lanza exception si querés obligar a que exista
            }

            $prod = $productos[$item['codigo']];
            $subtotal = $item['cantidad'] * $prod->precio;
            $total1 += $subtotal;

            PedidoDetalle::create([
                'pedido_id' => $pedido1->id,
                'producto_id' => $prod->id,
                'cantidad' => $item['cantidad'],
                'precio_unitario' => $prod->precio,
                'subtotal' => $subtotal,
                'aud_usuario' => 'seed',
            ]);
        }

        $pedido1->update(['total_estimado' => $total1]);

        // Pedido 2: Santa Tecla, ENVIADO
        $pedido2 = Pedido::create([
            'sucursal_codigo' => $sucursales['Santa Tecla'],
            'centro_costo_codigo' => $centros['Centro Costo Santa Tecla'],
            'semana_inicio' => now()->addWeek()->startOfWeek(),
            'semana_fin' => now()->addWeek()->endOfWeek(),
            'estado' => 'ENVIADO',
            'total_estimado' => 0,
            'aud_usuario' => 'seed',
        ]);

        $items2 = [
            ['codigo' => 'A002', 'cantidad' => 8],
            ['codigo' => 'CP02', 'cantidad' => 3],
            ['codigo' => 'X01', 'cantidad' => 20],
        ];

        $total2 = 0;
        foreach ($items2 as $item) {
            if (!isset($productos[$item['codigo']])) {
                continue;
            }

            $prod = $productos[$item['codigo']];
            $subtotal = $item['cantidad'] * $prod->precio;
            $total2 += $subtotal;

            PedidoDetalle::create([
                'pedido_id' => $pedido2->id,
                'producto_id' => $prod->id,
                'cantidad' => $item['cantidad'],
                'precio_unitario' => $prod->precio,
                'subtotal' => $subtotal,
                'aud_usuario' => 'seed',
            ]);
        }

        $pedido2->update(['total_estimado' => $total2]);
    }
}