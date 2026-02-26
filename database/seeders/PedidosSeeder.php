<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sucursal;
use App\Models\CentroCosto;
use App\Models\Producto;
use App\Models\Pedido;
use App\Models\PedidoDetalle;

class PedidosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sucursales = Sucursal::pluck('id', 'nombre');
        $centros = CentroCosto::pluck('id', 'codigo');
        $productos = Producto::on('pagos')->get()->keyBy('codigo');

        // Pedido 1: Guírola, BORRADOR
        $pedido1 = Pedido::on('pagos')->create([
            'sucursal_id' => $sucursales['Guírola'],
            'centro_costo_id' => $centros['CECO_GUIROLA'],
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
            $prod = $productos[$item['codigo']];
            $subtotal = $item['cantidad'] * $prod->precio;
            $total1 += $subtotal;
            PedidoDetalle::on('pagos')->create([
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
        $pedido2 = \App\Models\Pedido::create([
            'sucursal_id' => $sucursales['Santa Tecla'],
            'centro_costo_id' => $centros['CECO_STA_TECLA'],
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
            $prod = $productos[$item['codigo']];
            $subtotal = $item['cantidad'] * $prod->precio;
            $total2 += $subtotal;
            \App\Models\PedidoDetalle::create([
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
