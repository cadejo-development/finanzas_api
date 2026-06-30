<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaOrden;
use App\Models\Ventas\VentaCliente;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    // ── Orden individual (Excel) ───────────────────────────────────────────────
    public function ordenExcel(int $id): StreamedResponse
    {
        $orden = VentaOrden::with(['cliente', 'items'])->findOrFail($id);

        $ss    = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Orden #' . $orden->id);

        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(42);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(14);
        $sheet->getColumnDimension('E')->setWidth(10);
        $sheet->getColumnDimension('F')->setWidth(14);

        $row = 1;

        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("A{$row}", 'CADEJO BREWING COMPANY');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal('center');
        $sheet->getRowDimension($row)->setRowHeight(22);
        $row++;

        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("A{$row}", 'Orden de Venta #' . $orden->id);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal('center');
        $sheet->getRowDimension($row)->setRowHeight(18);
        $row++;
        $row++;

        $cliente  = $orden->cliente;
        $infoRows = [
            ['Cliente',         $cliente?->nombres ?? '—'],
            ['NIT',             $cliente?->nit ?? '—'],
            ['Tipo de venta',   strtoupper($orden->tipo_venta)],
            ['Plazo',           $orden->plazo_solicitado ? $orden->plazo_solicitado . ' días' : 'Contado'],
            ['Estado',          strtoupper(str_replace('_', ' ', $orden->estado))],
            ['Fecha',           $orden->created_at?->format('d/m/Y')],
            ['Creado por',      $orden->creado_por ?? '—'],
            ['Aprobado por',    $orden->aprobado_por ?? '—'],
        ];

        foreach ($infoRows as [$label, $value]) {
            $sheet->mergeCells("A{$row}:B{$row}");
            $sheet->setCellValue("A{$row}", $label);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->mergeCells("C{$row}:F{$row}");
            $sheet->setCellValue("C{$row}", $value);
            $sheet->getRowDimension($row)->setRowHeight(16);
            $row++;
        }

        if ($orden->notas) {
            $sheet->mergeCells("A{$row}:B{$row}");
            $sheet->setCellValue("A{$row}", 'Notas');
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->mergeCells("C{$row}:F{$row}");
            $sheet->setCellValue("C{$row}", $orden->notas);
            $sheet->getRowDimension($row)->setRowHeight(16);
            $row++;
        }

        $row++;

        $headers = ['#', 'Producto', 'Cantidad', 'Precio Unit.', 'IVA', 'Total'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(65 + $i) . $row, $h);
        }
        $sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true);
        $sheet->getRowDimension($row)->setRowHeight(16);
        $row++;

        $fmt = fn($n) => '$' . number_format($n, 2);
        foreach ($orden->items as $idx => $item) {
            $sheet->setCellValue("A{$row}", $idx + 1);
            $sheet->setCellValue("B{$row}", $item->nombre_producto . ($item->exento ? ' (exento)' : ''));
            $sheet->setCellValue("C{$row}", $item->cantidad);
            $sheet->setCellValue("D{$row}", $fmt($item->precio_unitario));
            $sheet->setCellValue("E{$row}", $item->iva > 0 ? $fmt($item->iva) : '—');
            $sheet->setCellValue("F{$row}", $fmt($item->total));
            $sheet->getRowDimension($row)->setRowHeight(15);
            $row++;
        }

        $row++;
        foreach ([['Subtotal', $fmt($orden->subtotal)], ['IVA (13%)', $fmt($orden->total_iva)], ['TOTAL', $fmt($orden->total)]] as [$l, $v]) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->mergeCells("E{$row}:F{$row}");
            $sheet->setCellValue("A{$row}", $l);
            $sheet->setCellValue("E{$row}", $v);
            if ($l === 'TOTAL') $sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:F{$row}")->getAlignment()->setHorizontal('right');
            $row++;
        }

        $filename = 'Orden_' . $orden->id . '_' . str_replace(' ', '_', $cliente?->nombres ?? 'cliente') . '.xlsx';

        return response()->stream(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    // ── Brilo: Importación Facturas de Venta (formato exacto Brilo) ───────────
    public function briloFacturas(Request $request): StreamedResponse
    {
        $estados = $request->filled('estado')
            ? [$request->estado]
            : ['aprobada', 'despachada', 'completada'];

        $q = VentaOrden::with(['cliente', 'items.producto'])
            ->whereIn('estado', $estados)
            ->orderBy('fecha_facturacion');

        if ($request->filled('fecha_desde')) {
            $q->whereDate('fecha_facturacion', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $q->whereDate('fecha_facturacion', '<=', $request->fecha_hasta);
        }
        if ($request->filled('tipo_documento')) {
            $q->where('tipo_documento', $request->tipo_documento);
        }
        if ($request->filled('tipo_venta')) {
            $q->where('tipo_venta', $request->tipo_venta);
        }

        $ordenes = $q->get();
        $fecha   = now()->format('Y-m-d');

        $ss    = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Formato Importacion Ventas');

        $headers = [
            'Tipo Factura', '# de Factura', '# Formulario Único', 'Fecha',
            'Cód. Cliente Facturado A', 'Nombre Cliente', 'Apellido Cliente', 'NIT Cliente',
            'Cód Sucursal del Cliente', 'Cód. Cliente CXC A', 'Cód. Cliente Enviado A',
            'Plazo de Crédito (en días)', 'Código Vendedor', 'Código Sucursal',
            'Código Caja Registradora', 'Código Tipo de Venta', '% IVA',
            '% de Percepción', '% de Retención', 'Concepto',
            'Código Emp Responsable', 'Dejar Mov Inv Pendiente',
            'Código Centro de Costo', 'Código Sub Centro de Costo',
            'Código de Producto', 'Descripción', 'Cantidad', 'Precio Unitario',
            '% de Descuento', 'Es Exento', 'Es No Sujeto',
            'Código Centro de Costo Item', 'Código Sub Centro de Costo Item',
            'Código de Ubicación', 'Número Lote', 'Código de Generación',
            'Sello de Recepción', '(Anulación) Código de Generación',
            'Número de Control', 'Referencia',
        ];

        foreach ($headers as $i => $h) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}1", $h);
        }
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);

        $row = 2;
        foreach ($ordenes as $orden) {
            $tipoDoc    = strtolower($orden->tipo_documento ?? 'ccf') === 'ccf' ? 'CCF' : 'FCF';
            $numFactura = str_pad($orden->id, 8, '0', STR_PAD_LEFT);
            $fechaDoc   = $orden->fecha_facturacion?->format('d/m/Y')
                       ?? $orden->created_at?->format('d/m/Y')
                       ?? '';
            $pctIva     = ($orden->cliente?->exento) ? 0 : 0.13;

            foreach ($orden->items as $item) {
                $vals = [
                    $tipoDoc,                                  // Tipo Factura
                    $numFactura,                               // # de Factura
                    '',                                        // # Formulario Único
                    $fechaDoc,                                 // Fecha
                    $orden->cliente?->brilo_id ?? '',          // Cód. Cliente Facturado A
                    $orden->cliente?->nombres ?? '',           // Nombre Cliente
                    '',                                        // Apellido Cliente
                    $orden->cliente?->nit ?? '',               // NIT Cliente
                    '',                                        // Cód Sucursal del Cliente
                    '',                                        // Cód. Cliente CXC A
                    '',                                        // Cód. Cliente Enviado A
                    $orden->plazo_solicitado ?? 0,             // Plazo de Crédito
                    $orden->cliente?->brilo_vendedor_id ?? '', // Código Vendedor
                    '',                                        // Código Sucursal
                    '',                                        // Código Caja Registradora
                    '',                                        // Código Tipo de Venta
                    $pctIva,                                   // % IVA
                    '',                                        // % de Percepción
                    '',                                        // % de Retención
                    '',                                        // Concepto
                    '',                                        // Código Emp Responsable
                    '',                                        // Dejar Mov Inv Pendiente
                    '',                                        // Código Centro de Costo
                    '',                                        // Código Sub Centro de Costo
                    $item->producto?->codigo ?? '',            // Código de Producto
                    $item->nombre_producto,                    // Descripción
                    $item->cantidad,                           // Cantidad
                    $item->precio_unitario,                    // Precio Unitario
                    0,                                         // % de Descuento
                    $item->exento ? 'S' : 'N',                // Es Exento
                    'N',                                       // Es No Sujeto
                    '',                                        // Código Centro de Costo Item
                    '',                                        // Código Sub Centro de Costo Item
                    '',                                        // Código de Ubicación
                    '',                                        // Número Lote
                    '',                                        // Código de Generación
                    '',                                        // Sello de Recepción
                    '',                                        // (Anulación) Código de Generación
                    '',                                        // Número de Control
                    '',                                        // Referencia
                ];

                foreach ($vals as $i => $v) {
                    $col = Coordinate::stringFromColumnIndex($i + 1);
                    $sheet->setCellValue("{$col}{$row}", $v);
                }
                $row++;
            }
        }

        foreach (range(1, count($headers)) as $i) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        return response()->stream(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"VEN_Formato Importacion Facturas de Venta_{$fecha}.xlsx\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    // ── Brilo: Importación Clientes (formato exacto Brilo, 57 columnas) ───────
    public function briloClientes(Request $request): StreamedResponse
    {
        $q = VentaCliente::orderBy('nombres');
        if (!$request->boolean('incluir_inactivos')) {
            $q->where('activo', true);
        }
        $clientes = $q->get();
        $fecha    = now()->format('Y-m-d');

        $ss    = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Formato Importacion Clientes');

        $headers = [
            'Codigo', 'NIT/DUI', 'Personeria', 'Razon Social / Nombres',
            'Nombre Comercial / Apellidos', 'Exento', 'Tipo Contribuyente',
            'NRC/Carne', '% IVA', 'Giro', 'Limite de Credito', 'Plazo de Credito',
            '% de Descuento', 'Direccion', 'Telefono', 'Municipio', 'Pais',
            'Cuenta Contable', 'Codigo Vendedor', 'Codigo(s) Adicional(es)',
            'Profesion/Oficio', '# Documento de Identidad', 'Lugar de Expedicion',
            'Estatus', 'Propio En Fact.', 'Codigo Comi Fact.', 'Departamento',
            '# de Decimales en Facturas', 'Es Distribuidor',
            'Nombre Comercial (Persona Natural)', 'Fecha de Cambio de Estado', 'Email',
            'Telefono 2', 'Fax', 'Celular', 'Contacto', 'Fecha Cliente Desde',
            '# de Dias de Gracias a Plazo de Credito', 'Codigo Responsable(Empleado)',
            'Codigo de Paciente', 'Tipo de Doc. Default', 'Nombre Representante Legal',
            'Fecha de Nacimiento Representante Legal', '# Doc. De Identidad Representante Legal',
            'Lugar de Doc. Identidad De Representante Legal', 'NIT Representate Legal',
            'País Representante Legal', 'Nombre de Apoderado Legal',
            '# Doc. De Identidad Apoderado Legal', 'NIT Apoderado Legal',
            'Fecha de Vigencia Apoderado Legal', 'Latitud', 'Longitud',
            '% Retencion', 'Minimo Retencion', 'Act. Economica', 'Distrito',
        ];

        foreach ($headers as $i => $h) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}1", $h);
        }
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);

        $row = 2;
        foreach ($clientes as $c) {
            $personeria = ($c->nit && strlen(str_replace('-', '', $c->nit)) > 9) ? 'J' : 'N';

            $vals = array_pad([
                $c->brilo_id ?? '',        // Codigo
                $c->nit ?? '',             // NIT/DUI
                $personeria,               // Personeria
                $c->nombres,               // Razon Social / Nombres
                $c->nom_comercial ?? '',   // Nombre Comercial / Apellidos
                $c->exento ? 'Si' : 'No', // Exento
                '',                        // Tipo Contribuyente
                $c->registro_iva ?? '',    // NRC/Carne
                $c->exento ? 0 : 0.13,    // % IVA
                '',                        // Giro
                $c->limite_credito ?? 0,   // Limite de Credito
                $c->plazo_credito ?? 0,    // Plazo de Credito
                0,                         // % de Descuento
                $c->direccion ?? '',       // Direccion
                $c->telefono ?? '',        // Telefono
                '', '',                    // Municipio, Pais
                '',                        // Cuenta Contable
                $c->brilo_vendedor_id ?? '', // Codigo Vendedor
                '', '', '', '',            // Codigos adicionales, Profesion, # Doc, Lugar Expedicion
                $c->activo ? 'A' : 'I',   // Estatus
                '', '', '',                // Propio En Fact, Codigo Comi, Departamento
                '', '', '', '',            // # Decimales, Es Distribuidor, Nom Comercial PN, Fecha Cambio Estado
                $c->email ?? '',           // Email
            ], count($headers), '');

            foreach ($vals as $i => $v) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue("{$col}{$row}", $v);
            }
            $row++;
        }

        foreach (range(1, count($headers)) as $i) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        return response()->stream(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"VEN_Formato Importacion Clientes_{$fecha}.xlsx\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    // ── CSV Contabilidad ──────────────────────────────────────────────────────
    public function contabilidadCsv(Request $request)
    {
        $q = VentaOrden::with(['cliente'])
            ->where('facturado', true)
            ->orderBy('facturado_at');

        if ($request->filled('fecha_desde')) {
            $q->whereDate('facturado_at', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $q->whereDate('facturado_at', '<=', $request->fecha_hasta);
        }
        if ($request->filled('tipo_documento')) {
            $q->where('tipo_documento', $request->tipo_documento);
        }
        if ($request->filled('tipo_venta')) {
            $q->where('tipo_venta', $request->tipo_venta);
        }
        if ($request->filled('estado')) {
            $q->where('estado', $request->estado);
        }

        $ordenes = $q->get();
        $fecha   = now()->format('Y-m-d');

        return response()->stream(function () use ($ordenes) {
            $out = fopen('php://output', 'w');
            fputs($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'N° Orden', 'Fecha Factura', 'Tipo Doc.', 'Estado',
                'Cliente', 'NIT', 'Reg. IVA', 'Exento',
                'Subtotal', 'IVA', 'Total',
                'Tipo Venta', 'Plazo (días)',
                'Facturado por', 'Facturado en',
            ]);
            foreach ($ordenes as $o) {
                fputcsv($out, [
                    $o->id,
                    $o->fecha_facturacion?->format('d/m/Y'),
                    strtoupper($o->tipo_documento ?? 'CCF'),
                    $o->estado,
                    $o->cliente?->nombres ?? '',
                    $o->cliente?->nit ?? '',
                    $o->cliente?->registro_iva ?? '',
                    $o->cliente?->exento ? 'S' : 'N',
                    number_format($o->subtotal,  2, '.', ''),
                    number_format($o->total_iva, 2, '.', ''),
                    number_format($o->total,     2, '.', ''),
                    $o->tipo_venta,
                    $o->plazo_solicitado ?? 0,
                    $o->facturado_por ?? '',
                    $o->facturado_at?->format('d/m/Y H:i'),
                ]);
            }
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"VEN_Contabilidad_{$fecha}.csv\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }
}
