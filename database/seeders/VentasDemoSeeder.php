<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class VentasDemoSeeder extends Seeder
{
    protected $connection = 'compras';

    public function run(): void
    {
        $db = DB::connection('compras');

        // Limpiar tablas en orden por FK (PostgreSQL)
        $db->statement('TRUNCATE ventas_aprobaciones, ventas_orden_items, ventas_ordenes, ventas_productos, ventas_clientes RESTART IDENTITY CASCADE');

        // ── Clientes ──────────────────────────────────────────────────────────
        $clientes = [
            [
                'nombres'        => 'Supermercado La Colonia S.A.',
                'nom_comercial'  => 'La Colonia',
                'nit'            => '0614-220995-101-9',
                'registro_iva'   => 'RC-12345',
                'email'          => 'compras@lacolonia.com.sv',
                'telefono'       => '2234-5678',
                'direccion'      => 'Blvd. del Hipódromo 430, San Salvador',
                'exento'         => false,
                'plazo_credito'  => 30,
                'limite_credito' => 8000.00,
                'brilo_status'   => 1,
                'activo'         => true,
            ],
            [
                'nombres'        => 'Hotel Crowne Plaza San Salvador',
                'nom_comercial'  => 'Crowne Plaza',
                'nit'            => '0614-180285-103-4',
                'registro_iva'   => 'RC-67890',
                'email'          => 'eventos@crowneplaza.com.sv',
                'telefono'       => '2524-6000',
                'direccion'      => 'Alameda Manuel E. Araujo 89, San Salvador',
                'exento'         => true,
                'plazo_credito'  => 30,
                'limite_credito' => 15000.00,
                'brilo_status'   => 1,
                'activo'         => true,
            ],
            [
                'nombres'        => 'Bar El Árbol Verde',
                'nom_comercial'  => 'El Árbol',
                'nit'            => '0614-050788-105-2',
                'registro_iva'   => null,
                'email'          => 'arbol.verde@gmail.com',
                'telefono'       => '7890-1234',
                'direccion'      => 'Col. San Benito, Calle La Reforma 112',
                'exento'         => false,
                'plazo_credito'  => 15,
                'limite_credito' => 2500.00,
                'brilo_status'   => 1,
                'activo'         => true,
            ],
            [
                'nombres'        => 'Restaurante El Bodegón',
                'nom_comercial'  => 'El Bodegón',
                'nit'            => '0614-120390-201-7',
                'registro_iva'   => 'RC-44321',
                'email'          => 'admin@elbodegon.sv',
                'telefono'       => '2264-9900',
                'direccion'      => 'Zona Rosa, Calle El Mirador 55, San Salvador',
                'exento'         => false,
                'plazo_credito'  => 30,
                'limite_credito' => 4000.00,
                'brilo_status'   => 1,
                'activo'         => true,
            ],
            [
                'nombres'        => 'La Bodega del Chef',
                'nom_comercial'  => 'Bodega del Chef',
                'nit'            => null,
                'registro_iva'   => null,
                'email'          => 'bodegadelchef@outlook.com',
                'telefono'       => '7654-3210',
                'direccion'      => 'Santa Elena, Antiguo Cuscatlán',
                'exento'         => false,
                'plazo_credito'  => 15,
                'limite_credito' => 1500.00,
                'brilo_status'   => 1,
                'activo'         => true,
            ],
            [
                'nombres'        => 'Ana García',
                'nom_comercial'  => null,
                'nit'            => null,
                'registro_iva'   => null,
                'email'          => 'ana.garcia@gmail.com',
                'telefono'       => '7711-2233',
                'direccion'      => 'Residencial Las Colinas, Santa Tecla',
                'exento'         => false,
                'plazo_credito'  => 0,
                'limite_credito' => 0,
                'brilo_status'   => 1,
                'activo'         => true,
            ],
            [
                'nombres'        => 'Distribuidora Cuscatlán S.A. de C.V.',
                'nom_comercial'  => 'Dist. Cuscatlán',
                'nit'            => '0614-310189-102-8',
                'registro_iva'   => 'RC-99001',
                'email'          => 'ventas@distcuscatlan.com',
                'telefono'       => '2222-0000',
                'direccion'      => 'Zona Industrial, San Marcos',
                'exento'         => false,
                'plazo_credito'  => 30,
                'limite_credito' => 6000.00,
                'brilo_status'   => 1,
                'activo'         => true,
            ],
            [
                'nombres'        => 'Carlos Mendoza',
                'nom_comercial'  => null,
                'nit'            => null,
                'registro_iva'   => null,
                'email'          => null,
                'telefono'       => '7900-5566',
                'direccion'      => null,
                'exento'         => false,
                'plazo_credito'  => 0,
                'limite_credito' => 0,
                'brilo_status'   => 1,
                'activo'         => true,
            ],
        ];

        $now = Carbon::now();
        foreach ($clientes as &$c) {
            $c['created_at'] = $now;
            $c['updated_at'] = $now;
        }
        $db->table('ventas_clientes')->insert($clientes);

        $cIds = $db->table('ventas_clientes')->orderBy('id')->pluck('id')->all();
        [$colonia, $crowne, $arbol, $bodegon, $bodegaChef, $ana, $distCusc, $carlos] = $cIds;

        // ── Productos ─────────────────────────────────────────────────────────
        $productos = [
            ['codigo' => 'CB-AMBER-330',   'nombre' => 'Cerveza Amber Ale 330ml',         'exento' => true,  'precio' => 2.50,  'existencias' => 480, 'bodega' => 'Producción'],
            ['codigo' => 'CB-AMBER-600',   'nombre' => 'Cerveza Amber Ale 600ml',         'exento' => true,  'precio' => 4.25,  'existencias' => 240, 'bodega' => 'Producción'],
            ['codigo' => 'CB-WHITE-330',   'nombre' => 'Cerveza White Ale 330ml',         'exento' => true,  'precio' => 2.50,  'existencias' => 360, 'bodega' => 'Producción'],
            ['codigo' => 'CB-WHITE-600',   'nombre' => 'Cerveza White Ale 600ml',         'exento' => true,  'precio' => 4.25,  'existencias' => 180, 'bodega' => 'Producción'],
            ['codigo' => 'CB-DARK-330',    'nombre' => 'Cerveza Dark Ale 330ml',          'exento' => true,  'precio' => 2.75,  'existencias' => 300, 'bodega' => 'Producción'],
            ['codigo' => 'CB-DARK-600',    'nombre' => 'Cerveza Dark Ale 600ml',          'exento' => true,  'precio' => 4.75,  'existencias' => 120, 'bodega' => 'Producción'],
            ['codigo' => 'CB-IPA-330',     'nombre' => 'Cerveza IPA 330ml',               'exento' => true,  'precio' => 3.00,  'existencias' => 216, 'bodega' => 'Producción'],
            ['codigo' => 'CB-CRAFT-KEG',   'nombre' => 'Barril 20L Cerveza Artesanal',    'exento' => true,  'precio' => 95.00, 'existencias' => 15,  'bodega' => 'Producción'],
            ['codigo' => 'CB-PROMO-12',    'nombre' => 'Pack 12 cervezas mixtas 330ml',   'exento' => true,  'precio' => 26.00, 'existencias' => 60,  'bodega' => 'Almacén'],
            ['codigo' => 'CB-MERCH-GLASS', 'nombre' => 'Vaso Cadejo vidrio 500ml',        'exento' => false, 'precio' => 8.50,  'existencias' => 80,  'bodega' => 'Almacén'],
            ['codigo' => 'CB-MERCH-CAP',   'nombre' => 'Gorra Cadejo Brewing',            'exento' => false, 'precio' => 12.00, 'existencias' => 45,  'bodega' => 'Almacén'],
            ['codigo' => 'CB-EVENT-TASTING','nombre' => 'Cata guiada de cervezas (por persona)', 'exento' => false, 'precio' => 18.00, 'existencias' => 50, 'bodega' => null],
        ];

        foreach ($productos as &$p) {
            $p['created_at'] = $now;
            $p['updated_at'] = $now;
        }
        $db->table('ventas_productos')->insert($productos);
        $pIds = $db->table('ventas_productos')->orderBy('id')->pluck('id', 'codigo')->all();

        // ── Helper para calcular totales de items ─────────────────────────────
        $buildItems = function (array $lineas, array $pIds, int $ordenId) use ($db, $now) {
            $subtotalOrden = 0; $ivaOrden = 0; $totalOrden = 0;
            $rows = [];
            foreach ($lineas as [$codigo, $qty]) {
                $prod = $db->table('ventas_productos')->where('id', $pIds[$codigo])->first();
                $sub  = round($qty * $prod->precio, 2);
                $iva  = $prod->exento ? 0 : round($sub * 0.13, 2);
                $tot  = $sub + $iva;
                $subtotalOrden += $sub; $ivaOrden += $iva; $totalOrden += $tot;
                $rows[] = [
                    'orden_id'       => $ordenId,
                    'producto_id'    => $prod->id,
                    'nombre_producto'=> $prod->nombre,
                    'cantidad'       => $qty,
                    'precio_unitario'=> $prod->precio,
                    'exento'         => $prod->exento,
                    'subtotal'       => $sub,
                    'iva'            => $iva,
                    'total'          => $tot,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }
            $db->table('ventas_orden_items')->insert($rows);
            return [round($subtotalOrden, 2), round($ivaOrden, 2), round($totalOrden, 2)];
        };

        // ── Órdenes ───────────────────────────────────────────────────────────

        // 1. Colonia — completada, crédito 30 días
        $o1 = $db->table('ventas_ordenes')->insertGetId([
            'cliente_id'     => $colonia,
            'tipo_venta'     => 'credito',
            'plazo_solicitado' => 30,
            'estado'         => 'completada',
            'creado_por'     => 'vendedor',
            'aprobado_por'   => 'rodrigo',
            'aprobado_at'    => $now->copy()->subDays(8),
            'notas'          => 'Pedido mensual regular',
            'subtotal' => 0, 'total_iva' => 0, 'total' => 0,
            'created_at'     => $now->copy()->subDays(10),
            'updated_at'     => $now->copy()->subDays(8),
        ]);
        [$s,$i,$t] = $buildItems([['CB-AMBER-330', 120], ['CB-WHITE-330', 60], ['CB-DARK-330', 48]], $pIds, $o1);
        $db->table('ventas_ordenes')->where('id', $o1)->update(['subtotal'=>$s,'total_iva'=>$i,'total'=>$t]);

        // 2. Crowne Plaza — aprobada, crédito 30 días, exento
        $o2 = $db->table('ventas_ordenes')->insertGetId([
            'cliente_id'     => $crowne,
            'tipo_venta'     => 'credito',
            'plazo_solicitado' => 30,
            'estado'         => 'aprobada',
            'creado_por'     => 'vendedor',
            'aprobado_por'   => 'rodrigo',
            'aprobado_at'    => $now->copy()->subDays(2),
            'notas'          => 'Evento corporativo 250 personas — 14 junio',
            'subtotal' => 0, 'total_iva' => 0, 'total' => 0,
            'created_at'     => $now->copy()->subDays(3),
            'updated_at'     => $now->copy()->subDays(2),
        ]);
        [$s,$i,$t] = $buildItems([['CB-CRAFT-KEG', 8], ['CB-AMBER-330', 144], ['CB-WHITE-330', 96]], $pIds, $o2);
        $db->table('ventas_ordenes')->where('id', $o2)->update(['subtotal'=>$s,'total_iva'=>$i,'total'=>$t]);

        // 3. El Árbol — pendiente_aprobacion (exceso de límite)
        $o3 = $db->table('ventas_ordenes')->insertGetId([
            'cliente_id'     => $arbol,
            'tipo_venta'     => 'credito',
            'plazo_solicitado' => 15,
            'estado'         => 'pendiente_aprobacion',
            'creado_por'     => 'vendedor',
            'notas'          => 'Pedido para fin de semana largo',
            'subtotal' => 0, 'total_iva' => 0, 'total' => 0,
            'created_at'     => $now->copy()->subHours(5),
            'updated_at'     => $now->copy()->subHours(5),
        ]);
        [$s,$i,$t] = $buildItems([['CB-AMBER-600', 72], ['CB-IPA-330', 96], ['CB-MERCH-GLASS', 12]], $pIds, $o3);
        $db->table('ventas_ordenes')->where('id', $o3)->update(['subtotal'=>$s,'total_iva'=>$i,'total'=>$t]);

        // 4. El Bodegón — completada, crédito
        $o4 = $db->table('ventas_ordenes')->insertGetId([
            'cliente_id'     => $bodegon,
            'tipo_venta'     => 'credito',
            'plazo_solicitado' => 30,
            'estado'         => 'completada',
            'creado_por'     => 'vendedor',
            'aprobado_por'   => 'rodrigo',
            'aprobado_at'    => $now->copy()->subDays(15),
            'subtotal' => 0, 'total_iva' => 0, 'total' => 0,
            'created_at'     => $now->copy()->subDays(18),
            'updated_at'     => $now->copy()->subDays(15),
        ]);
        [$s,$i,$t] = $buildItems([['CB-AMBER-330', 96], ['CB-WHITE-600', 48], ['CB-DARK-330', 36]], $pIds, $o4);
        $db->table('ventas_ordenes')->where('id', $o4)->update(['subtotal'=>$s,'total_iva'=>$i,'total'=>$t]);

        // 5. Ana García — contado, completada
        $o5 = $db->table('ventas_ordenes')->insertGetId([
            'cliente_id'     => $ana,
            'tipo_venta'     => 'contado',
            'plazo_solicitado' => 0,
            'estado'         => 'completada',
            'creado_por'     => 'vendedor',
            'notas'          => 'Quinceañera — necesita entrega el sábado',
            'subtotal' => 0, 'total_iva' => 0, 'total' => 0,
            'created_at'     => $now->copy()->subDays(4),
            'updated_at'     => $now->copy()->subDays(4),
        ]);
        [$s,$i,$t] = $buildItems([['CB-PROMO-12', 5], ['CB-MERCH-GLASS', 20], ['CB-EVENT-TASTING', 8]], $pIds, $o5);
        $db->table('ventas_ordenes')->where('id', $o5)->update(['subtotal'=>$s,'total_iva'=>$i,'total'=>$t]);

        // 6. Dist. Cuscatlán — pendiente_aprobacion (plazo especial)
        $o6 = $db->table('ventas_ordenes')->insertGetId([
            'cliente_id'     => $distCusc,
            'tipo_venta'     => 'credito',
            'plazo_solicitado' => 45,
            'estado'         => 'pendiente_aprobacion',
            'creado_por'     => 'vendedor',
            'notas'          => 'Cliente solicita 45 días por cierre de ejercicio fiscal',
            'subtotal' => 0, 'total_iva' => 0, 'total' => 0,
            'created_at'     => $now->copy()->subHours(2),
            'updated_at'     => $now->copy()->subHours(2),
        ]);
        [$s,$i,$t] = $buildItems([['CB-AMBER-330', 240], ['CB-WHITE-330', 120], ['CB-DARK-600', 60]], $pIds, $o6);
        $db->table('ventas_ordenes')->where('id', $o6)->update(['subtotal'=>$s,'total_iva'=>$i,'total'=>$t]);

        // 7. Carlos Mendoza — contado, borrador
        $o7 = $db->table('ventas_ordenes')->insertGetId([
            'cliente_id'     => $carlos,
            'tipo_venta'     => 'contado',
            'plazo_solicitado' => 0,
            'estado'         => 'borrador',
            'creado_por'     => 'vendedor',
            'subtotal' => 0, 'total_iva' => 0, 'total' => 0,
            'created_at'     => $now->copy()->subMinutes(30),
            'updated_at'     => $now->copy()->subMinutes(30),
        ]);
        [$s,$i,$t] = $buildItems([['CB-IPA-330', 24], ['CB-MERCH-CAP', 3]], $pIds, $o7);
        $db->table('ventas_ordenes')->where('id', $o7)->update(['subtotal'=>$s,'total_iva'=>$i,'total'=>$t]);

        // 8. La Bodega del Chef — aprobada, crédito
        $o8 = $db->table('ventas_ordenes')->insertGetId([
            'cliente_id'     => $bodegaChef,
            'tipo_venta'     => 'credito',
            'plazo_solicitado' => 15,
            'estado'         => 'aprobada',
            'creado_por'     => 'vendedor',
            'aprobado_por'   => 'rodrigo',
            'aprobado_at'    => $now->copy()->subDays(1),
            'subtotal' => 0, 'total_iva' => 0, 'total' => 0,
            'created_at'     => $now->copy()->subDays(2),
            'updated_at'     => $now->copy()->subDays(1),
        ]);
        [$s,$i,$t] = $buildItems([['CB-AMBER-600', 36], ['CB-WHITE-330', 48]], $pIds, $o8);
        $db->table('ventas_ordenes')->where('id', $o8)->update(['subtotal'=>$s,'total_iva'=>$i,'total'=>$t]);

        // ── Aprobaciones pendientes ───────────────────────────────────────────
        $o3Data = $db->table('ventas_ordenes')->where('id', $o3)->first();

        $db->table('ventas_aprobaciones')->insert([
            [
                'tipo'          => 'exceso_limite',
                'orden_id'      => $o3,
                'cliente_id'    => $arbol,
                'detalle'       => 'La orden #' . $o3 . ' por $' . number_format($o3Data->total, 2) . ' excede el límite de crédito de $2,500.00 de Bar El Árbol Verde.',
                'estado'        => 'pendiente',
                'solicitado_por'=> 'vendedor',
                'created_at'    => $now->copy()->subHours(5),
                'updated_at'    => $now->copy()->subHours(5),
            ],
            [
                'tipo'          => 'cambio_credito',
                'orden_id'      => $o6,
                'cliente_id'    => $distCusc,
                'detalle'       => 'Vendedor solicita ampliar plazo a 45 días para Distribuidora Cuscatlán (orden #' . $o6 . '). Política estándar es 30 días.',
                'estado'        => 'pendiente',
                'solicitado_por'=> 'vendedor',
                'created_at'    => $now->copy()->subHours(2),
                'updated_at'    => $now->copy()->subHours(2),
            ],
        ]);

        $this->command->info('✓ VentasDemoSeeder: 8 clientes, 12 productos, 8 órdenes, 2 aprobaciones pendientes.');
    }
}
