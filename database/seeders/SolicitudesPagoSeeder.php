<?php

namespace Database\Seeders;

use App\Models\Contribuyente;
use App\Models\FormaPago;
use App\Models\Proveedor;
use App\Models\SolicitudPago;
use App\Models\SolicitudPagoDetalle;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SolicitudesPagoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $proveedores = Proveedor::on('pagos')->pluck('id', 'nombre');
        $contribuyentes = Contribuyente::on('pagos')->pluck('id', 'codigo');
        $formas = FormaPago::on('pagos')->pluck('id', 'codigo');

        // Solicitud 1: GRAN_CONTRIBUYENTE, JURIDICA, servicio
        $solicitud1 = SolicitudPago::on('pagos')->create([
            'codigo' => 'SP-0001',
            'fecha_solicitud' => now()->subDays(2),
            'fecha_pago' => now()->addDays(3),
            'forma_pago_id' => $formas['transferencia'],
            'proveedor_id' => $proveedores['Proveedor Genérico S.A. de C.V.'],
            'contribuyente_id' => $contribuyentes['gran_contribuyente'],
            'personeria' => 'JURIDICA',
            'es_servicio' => true,
            'tipo_gasto' => 'OPEX',
            'estado' => 'BORRADOR',
            'aud_usuario' => 'seed',
        ]);
        $detalles1 = [
            ['concepto' => 'Consultoría legal', 'centro_costo_id' => 1, 'cantidad' => 2, 'precio_unitario' => 500],
            ['concepto' => 'Capacitación', 'centro_costo_id' => 1, 'cantidad' => 1, 'precio_unitario' => 300],
        ];
        foreach ($detalles1 as $d) {
            SolicitudPagoDetalle::on('pagos')->create([
                'solicitud_pago_id' => $solicitud1->id,
                'concepto' => $d['concepto'],
                'centro_costo_id' => $d['centro_costo_id'],
                'cantidad' => $d['cantidad'],
                'precio_unitario' => $d['precio_unitario'],
                'subtotal_linea' => $d['cantidad'] * $d['precio_unitario'],
                'aud_usuario' => 'seed',
            ]);
        }

        // Solicitud 2: OTROS, NATURAL, no servicio
        $solicitud2 = SolicitudPago::on('pagos')->create([
            'codigo' => 'SP-0002',
            'fecha_solicitud' => now()->subDays(1),
            'fecha_pago' => now()->addDays(2),
            'forma_pago_id' => $formas['cheque'],
            'proveedor_id' => $proveedores['Servicios Profesionales El Salvador'],
            'contribuyente_id' => $contribuyentes['otros'],
            'personeria' => 'NATURAL',
            'es_servicio' => false,
            'tipo_gasto' => 'CAPEX',
            'estado' => 'BORRADOR',
            'aud_usuario' => 'seed',
        ]);
        $detalles2 = [
            ['concepto' => 'Compra de equipo', 'centro_costo_id' => 2, 'cantidad' => 1, 'precio_unitario' => 1200],
            ['concepto' => 'Instalación', 'centro_costo_id' => 2, 'cantidad' => 1, 'precio_unitario' => 200],
        ];
        foreach ($detalles2 as $d) {
            SolicitudPagoDetalle::on('pagos')->create([
                'solicitud_pago_id' => $solicitud2->id,
                'concepto' => $d['concepto'],
                'centro_costo_id' => $d['centro_costo_id'],
                'cantidad' => $d['cantidad'],
                'precio_unitario' => $d['precio_unitario'],
                'subtotal_linea' => $d['cantidad'] * $d['precio_unitario'],
                'aud_usuario' => 'seed',
            ]);
        }
    }
}
