<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaOrden;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill, Font};
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use App\Models\Ventas\VentaCliente;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function ordenExcel(int $id): StreamedResponse
    {
        $orden = VentaOrden::with(['cliente', 'items'])->findOrFail($id);

        $ss    = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Orden #' . $orden->id);

        // ── Paleta ────────────────────────────────────────────────────────────
        $AMBER  = 'FFB300';   // encabezados principales
        $DARK   = '1C1A17';   // fondo oscuro
        $STONE  = '3D3A35';   // filas alternas
        $WHITE  = 'FFFFFF';
        $GREEN  = '10B981';
        $LGRAY  = 'F3F0EC';   // fondo claro filas impares

        // ── Anchos de columna ─────────────────────────────────────────────────
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(42);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(14);
        $sheet->getColumnDimension('E')->setWidth(10);
        $sheet->getColumnDimension('F')->setWidth(14);

        $row = 1;

        // ── Encabezado empresa ────────────────────────────────────────────────
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("A{$row}", 'CADEJO BREWING COMPANY');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 16, 'color' => ['rgb' => $AMBER]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(28);
        $row++;

        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("A{$row}", 'Orden de Venta #' . $orden->id);
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => $WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(20);
        $row++;

        // ── Separador ─────────────────────────────────────────────────────────
        $sheet->getRowDimension($row)->setRowHeight(6);
        $row++;

        // ── Bloque info ───────────────────────────────────────────────────────
        $infoStyle = [
            'font' => ['size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $LGRAY]],
        ];
        $labelStyle = array_merge_recursive($infoStyle, ['font' => ['bold' => true, 'color' => ['rgb' => '5C5853']]]);

        $cliente = $orden->cliente;
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
            $sheet->getStyle("A{$row}")->applyFromArray($labelStyle);

            $sheet->mergeCells("C{$row}:F{$row}");
            $sheet->setCellValue("C{$row}", $value);
            $sheet->getStyle("C{$row}")->applyFromArray($infoStyle);

            $sheet->getRowDimension($row)->setRowHeight(16);
            $row++;
        }

        // Notas
        if ($orden->notas) {
            $sheet->mergeCells("A{$row}:B{$row}");
            $sheet->setCellValue("A{$row}", 'Notas');
            $sheet->getStyle("A{$row}")->applyFromArray($labelStyle);
            $sheet->mergeCells("C{$row}:F{$row}");
            $sheet->setCellValue("C{$row}", $orden->notas);
            $sheet->getStyle("C{$row}")->applyFromArray($infoStyle);
            $sheet->getRowDimension($row)->setRowHeight(16);
            $row++;
        }

        $row++; // espacio

        // ── Encabezado tabla productos ────────────────────────────────────────
        $headers = ['#', 'Producto', 'Cantidad', 'Precio Unit.', 'IVA', 'Total'];
        foreach ($headers as $i => $h) {
            $col = chr(65 + $i); // A, B, C...
            $sheet->setCellValue("{$col}{$row}", $h);
        }
        $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => $DARK]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $AMBER]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '8B6914']]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(18);
        $row++;

        // ── Filas de productos ────────────────────────────────────────────────
        $fmt = fn($n) => '$' . number_format($n, 2);
        foreach ($orden->items as $idx => $item) {
            $bgRgb = $idx % 2 === 0 ? $LGRAY : $WHITE;
            $sheet->setCellValue("A{$row}", $idx + 1);
            $sheet->setCellValue("B{$row}", $item->nombre_producto . ($item->exento ? ' (exento)' : ''));
            $sheet->setCellValue("C{$row}", $item->cantidad);
            $sheet->setCellValue("D{$row}", $fmt($item->precio_unitario));
            $sheet->setCellValue("E{$row}", $item->iva > 0 ? $fmt($item->iva) : '—');
            $sheet->setCellValue("F{$row}", $fmt($item->total));

            $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgRgb]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle("C{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("F{$row}")->getFont()->setBold(true);
            $sheet->getRowDimension($row)->setRowHeight(16);
            $row++;
        }

        $row++; // espacio

        // ── Totales ───────────────────────────────────────────────────────────
        $totales = [
            ['Subtotal',   $fmt($orden->subtotal),  false],
            ['IVA (13%)',  $fmt($orden->total_iva), false],
            ['TOTAL',      $fmt($orden->total),     true],
        ];
        foreach ($totales as [$label, $value, $bold]) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->mergeCells("E{$row}:F{$row}");
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("E{$row}", $value);

            $bg  = $bold ? $DARK : $LGRAY;
            $clr = $bold ? $AMBER : '3D3A35';
            $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
                'font'      => ['bold' => $bold, 'color' => ['rgb' => $clr], 'size' => $bold ? 12 : 10],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
            ]);
            $sheet->getRowDimension($row)->setRowHeight($bold ? 22 : 16);
            $row++;
        }

        // ── Pie de página ─────────────────────────────────────────────────────
        $row++;
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("A{$row}", 'Documento generado el ' . now()->format('d/m/Y H:i') . ' — Cadejo Brewing Company');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['italic' => true, 'size' => 8, 'color' => ['rgb' => 'A8A29E']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // ── Bordes externos de la tabla ───────────────────────────────────────
        $tableStart = 11 + count($infoRows) + ($orden->notas ? 1 : 0); // fila donde empieza la tabla
        // (simplificado: borde alrededor de la hoja completa)
        $lastRow = $row;
        $sheet->getStyle("A1:F{$lastRow}")->applyFromArray([
            'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D6D3CE']]],
        ]);

        // ── Stream al cliente ─────────────────────────────────────────────────
        $filename = 'Orden_' . $orden->id . '_' . str_replace(' ', '_', $cliente?->nombres ?? 'cliente') . '.xlsx';

        return response()->stream(function () use ($ss) {
            $writer = new Xlsx($ss);
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    // ── Brilo: Formato Importación Facturas de Venta ──────────────────────────
    public function briloFacturas(Request $request): StreamedResponse
    {
        $q = VentaOrden::with(['cliente', 'items'])
            ->whereIn('estado', ['aprobada', 'despachada', 'completada'])
            ->orderBy('created_at');

        if ($request->filled('fecha_desde')) {
            $q->whereDate('fecha_facturacion', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $q->whereDate('fecha_facturacion', '<=', $request->fecha_hasta);
        }

        $ordenes = $q->get();

        $ss    = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Importacion Facturas');

        // Encabezados según formato Brilo
        $headers = [
            'Tipo Doc.', 'N° Orden', 'Fecha Factura', 'Fecha Entrega',
            'Código Cliente', 'NIT Cliente', 'Nombre Cliente', 'Registro IVA',
            'Código Producto', 'Descripción Producto', 'Cantidad',
            'Precio Unit. s/IVA', 'Exento', 'IVA', 'Total Línea', 'Total Orden',
        ];

        foreach ($headers as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}1", $h);
        }

        $sheet->getStyle('A1:P1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '1C1A17']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFB300']],
        ]);

        $row = 2;
        foreach ($ordenes as $orden) {
            foreach ($orden->items as $item) {
                $vals = [
                    strtoupper($orden->tipo_documento ?? 'CCF'),
                    $orden->id,
                    $orden->fecha_facturacion?->format('d/m/Y'),
                    $orden->fecha_entrega?->format('d/m/Y'),
                    $orden->cliente?->brilo_id ?? $orden->cliente_id,
                    $orden->cliente?->nit ?? '',
                    $orden->cliente?->nombres ?? '',
                    $orden->cliente?->registro_iva ?? '',
                    $item->producto?->codigo ?? '',
                    $item->nombre_producto,
                    $item->cantidad,
                    $item->precio_unitario,
                    $item->exento ? 'S' : 'N',
                    $item->iva,
                    $item->total,
                    $orden->total,
                ];

                foreach ($vals as $i => $v) {
                    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                    $sheet->setCellValue("{$col}{$row}", $v);
                }
                $row++;
            }
        }

        // Auto-width
        foreach (range(1, 16) as $i) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fecha = now()->format('Y-m-d');
        return response()->stream(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"VEN_Facturas_Venta_{$fecha}.xlsx\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    // ── CSV Contabilidad ──────────────────────────────────────────────────────────
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

        $ordenes = $q->get();
        $fecha   = now()->format('Y-m-d');

        return response()->stream(function () use ($ordenes) {
            $out = fopen('php://output', 'w');
            fputs($out, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
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
                    number_format($o->subtotal,   2, '.', ''),
                    number_format($o->total_iva,  2, '.', ''),
                    number_format($o->total,      2, '.', ''),
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

    // ── Brilo: Formato Importación Clientes ───────────────────────────────────
    public function briloClientes(): StreamedResponse
    {
        $clientes = VentaCliente::where('activo', true)->orderBy('nombres')->get();

        $ss    = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Importacion Clientes');

        $headers = [
            'Código Brilo', 'Razón Social', 'Nombre Comercial', 'NIT', 'Registro IVA',
            'Dirección', 'Teléfono', 'Email', 'Exento', 'Límite Crédito', 'Plazo (días)',
        ];

        foreach ($headers as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}1", $h);
        }

        $sheet->getStyle('A1:K1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '1C1A17']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFB300']],
        ]);

        $row = 2;
        foreach ($clientes as $c) {
            $vals = [
                $c->brilo_id ?? '',
                $c->nombres,
                $c->nom_comercial ?? '',
                $c->nit ?? '',
                $c->registro_iva ?? '',
                $c->direccion ?? '',
                $c->telefono ?? '',
                $c->email ?? '',
                $c->exento ? 'S' : 'N',
                $c->limite_credito,
                $c->plazo_credito,
            ];
            foreach ($vals as $i => $v) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue("{$col}{$row}", $v);
            }
            $row++;
        }

        foreach (range(1, 11) as $i) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fecha = now()->format('Y-m-d');
        return response()->stream(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"VEN_Clientes_{$fecha}.xls\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }
}
