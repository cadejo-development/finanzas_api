<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Contribuyente;
use App\Models\FormaPago;
use App\Models\Proveedor;
use App\Models\SolicitudPago;
use App\Models\SolicitudPagoDetalle;

class SolicitudesPagoSeeder extends Seeder
{
    public function run(): void
    {
        // Truncar detalle primero (por FK) y luego la tabla principal
        SolicitudPagoDetalle::truncate();
        SolicitudPago::truncate();

        // Mapeos (key => id) según los usos posteriores
        $proveedores = Proveedor::pluck('id', 'nombre');        // ['Proveedor Genérico S.A. de C.V.' => 5, ...]
        $contribuyentes = Contribuyente::pluck('id', 'codigo'); // ['gran_contribuyente' => 2, ...]
        $formas = FormaPago::pluck('id', 'codigo');             // ['transferencia' => 1, 'cheque' => 2, ...]

        // Solicitud 1: GRAN_CONTRIBUYENTE, JURIDICA, servicio
        $solicitud1 = SolicitudPago::create([
            'codigo' => 'SP-0001',
            'fecha_solicitud' => now()->subDays(2),
            'fecha_pago' => now()->addDays(3),
            'forma_pago_id' => $formas['transferencia'] ?? null,
            'proveedor_id' => $proveedores['Proveedor Genérico S.A. de C.V.'] ?? null,
            'contribuyente_id' => $contribuyentes['gran_contribuyente'] ?? null,
            'personeria' => 'JURIDICA',
            'es_servicio' => true,
            'tipo_gasto' => 'OPEX',
            'estado' => 'BORRADOR',
            'aud_usuario' => 'seed',
        ]);

        $detalles1 = [
            ['concepto' => 'Consultoría legal', 'centro_costo_codigo' => 'CECO_GUIROLA', 'cantidad' => 2, 'precio_unitario' => 500],
            ['concepto' => 'Capacitación', 'centro_costo_codigo' => 'CECO_GUIROLA', 'cantidad' => 1, 'precio_unitario' => 300],
        ];

        foreach ($detalles1 as $d) {
            // safety: si por alguna razón $solicitud1 es null (falló create), saltamos
            if (! $solicitud1) break;

            SolicitudPagoDetalle::create([
                'solicitud_pago_id' => $solicitud1->id,
                'concepto' => $d['concepto'],
                'centro_costo_codigo' => $d['centro_costo_codigo'],
                'cantidad' => $d['cantidad'],
                'precio_unitario' => $d['precio_unitario'],
                'subtotal_linea' => $d['cantidad'] * $d['precio_unitario'],
                'aud_usuario' => 'seed',
            ]);
        }

        // Solicitud 2: OTROS, NATURAL, no servicio
        $solicitud2 = SolicitudPago::create([
            'codigo' => 'SP-0002',
            'fecha_solicitud' => now()->subDays(1),
            'fecha_pago' => now()->addDays(2),
            'forma_pago_id' => $formas['cheque'] ?? null,
            'proveedor_id' => $proveedores['Servicios Profesionales El Salvador'] ?? null,
            'contribuyente_id' => $contribuyentes['otros'] ?? null,
            'personeria' => 'NATURAL',
            'es_servicio' => false,
            'tipo_gasto' => 'CAPEX',
            'estado' => 'BORRADOR',
            'aud_usuario' => 'seed',
        ]);

        $detalles2 = [
            ['concepto' => 'Compra de equipo', 'centro_costo_codigo' => 'CECO_STA_TECLA', 'cantidad' => 1, 'precio_unitario' => 1200],
            ['concepto' => 'Instalación', 'centro_costo_codigo' => 'CECO_STA_TECLA', 'cantidad' => 1, 'precio_unitario' => 200],
        ];

        foreach ($detalles2 as $d) {
            if (! $solicitud2) break;

            SolicitudPagoDetalle::create([
                'solicitud_pago_id' => $solicitud2->id,
                'concepto' => $d['concepto'],
                'centro_costo_codigo' => $d['centro_costo_codigo'],
                'cantidad' => $d['cantidad'],
                'precio_unitario' => $d['precio_unitario'],
                'subtotal_linea' => $d['cantidad'] * $d['precio_unitario'],
                'aud_usuario' => 'seed',
            ]);
        }
    }
}