<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventarioReporteController extends Controller
{
    // ── Colores ────────────────────────────────────────────────────────────────
    private const C = [
        'amber'   => 'FFCC44', 'amber_bg' => 'FFF8E1',
        'green'   => '2E7D32', 'green_bg' => 'E8F5E9',
        'red'     => 'C62828', 'red_bg'   => 'FFEBEE',
        'blue'    => '1565C0', 'blue_bg'  => 'E3F2FD',
        'gray'    => '616161', 'gray_bg'  => 'F5F5F5',
        'white'   => 'FFFFFF', 'black'    => '212121',
        'header'  => '1A1A2E', 'subhdr'   => '16213E',
    ];
    private const SECCIONES = ['SECOS', 'FRIOS', 'CUARTO FRIO', 'COCINA', 'BAR', 'FREEZER', 'CAJA'];

    // GET /api/compras/inventario/reporte-conteo?sucursal_id=X&fecha=YYYY-MM-DD
    public function generar(Request $request): StreamedResponse
    {
        $sucursalId = (int) $request->query('sucursal_id', 0);
        $fecha      = $request->query('fecha', '');

        if (!$sucursalId || !$fecha) {
            abort(422, 'sucursal_id y fecha son requeridos.');
        }

        // ── 1. Nombre de sucursal ──────────────────────────────────────────────
        $sucursal = DB::connection('pgsql')
            ->table('sucursales')
            ->where('id', $sucursalId)
            ->value('nombre');
        $sucursalName = strtoupper($sucursal ?? "SUCURSAL $sucursalId");

        // ── 2. Conteos del día ─────────────────────────────────────────────────
        $conteosRaw = DB::connection('compras')
            ->table('movimientos_inventario')
            ->where('sucursal_id', $sucursalId)
            ->where('tipo', 'conteo_fisico')
            ->whereDate('fecha', $fecha)
            ->orderByDesc('created_at')
            ->select('producto_id', 'detalle')
            ->get();

        $conteoMap = [];
        foreach ($conteosRaw as $c) {
            $d   = is_string($c->detalle) ? json_decode($c->detalle, true) : (array) ($c->detalle ?? []);
            $pid = (int) $c->producto_id;
            if (!isset($conteoMap[$pid])) {
                $conteoMap[$pid] = [
                    'total_contado' => $d['total_contado'] ?? null,
                    'secciones'     => $d['secciones'] ?? [],
                ];
            }
        }

        $prodIds = array_keys($conteoMap);
        if (empty($prodIds)) {
            abort(404, "No hay conteos para sucursal=$sucursalId en fecha=$fecha.");
        }

        // ── 3. Productos + BRILO + costo ─────────────────────────────────────
        $prods = DB::connection('compras')
            ->table('productos as p')
            ->leftJoin('inventarios as inv', fn ($j) =>
                $j->on('inv.producto_id', '=', 'p.id')->where('inv.sucursal_id', $sucursalId))
            ->leftJoin('categorias as cat', 'cat.id', '=', 'p.categoria_id')
            ->whereIn('p.id', $prodIds)
            ->select('p.id', 'p.codigo', 'p.nombre', 'p.unidad', 'p.costo',
                     'cat.nombre as categoria', 'inv.brilo_stock', 'inv.brilo_sync_at')
            ->orderBy('cat.nombre')
            ->orderBy('p.codigo')
            ->get();

        // ── 4. Consolidar filas ────────────────────────────────────────────────
        $filas = [];
        foreach ($prods as $p) {
            $c = $conteoMap[$p->id] ?? null;
            if (!$c || $c['total_contado'] === null) continue;

            $conteo    = round((float) $c['total_contado'], 4);
            $brilo     = $p->brilo_stock !== null ? round((float) $p->brilo_stock, 4) : null;
            $diff      = $brilo !== null ? round($conteo - $brilo, 4) : null;
            $diffPct   = ($brilo !== null && $brilo != 0) ? round($diff / $brilo * 100, 2) : null;
            $costo     = $p->costo !== null ? round((float) $p->costo, 2) : null;
            $costoDiff = ($diff !== null && $costo !== null) ? round($diff * $costo, 2) : null;

            if ($brilo === null)             $tipo = 'SIN BRILO';
            elseif (abs($diff) <= 0.01)      $tipo = 'OK';
            elseif ($diff < 0)               $tipo = 'FALTANTE';
            else                             $tipo = 'SOBRANTE';

            $filas[] = [
                'sucursal'  => $sucursalName,
                'codigo'    => $p->codigo,
                'nombre'    => $p->nombre,
                'categoria' => $p->categoria ?? '—',
                'unidad'    => $p->unidad,
                'brilo'     => $brilo,
                'conteo'    => $conteo,
                'secciones' => $c['secciones'],
                'diff'      => $diff,
                'diffPct'   => $diffPct,
                'tipo'      => $tipo,
                'costo'     => $costo,
                'costoDiff' => $costoDiff,
            ];
        }

        // ── 5. Estadísticas globales ───────────────────────────────────────────
        $nFalt    = count(array_filter($filas, fn ($f) => $f['tipo'] === 'FALTANTE'));
        $nSobr    = count(array_filter($filas, fn ($f) => $f['tipo'] === 'SOBRANTE'));
        $nOk      = count(array_filter($filas, fn ($f) => $f['tipo'] === 'OK'));
        $nSinBril = count(array_filter($filas, fn ($f) => $f['tipo'] === 'SIN BRILO'));
        $nConBril = count(array_filter($filas, fn ($f) => $f['brilo'] !== null));

        $totalCostoFalt = round(array_sum(array_map(
            fn ($f) => $f['tipo'] === 'FALTANTE' && $f['costoDiff'] !== null ? abs($f['costoDiff']) : 0, $filas
        )), 2);
        $totalCostoSobr = round(array_sum(array_map(
            fn ($f) => $f['tipo'] === 'SOBRANTE' && $f['costoDiff'] !== null ? $f['costoDiff'] : 0, $filas
        )), 2);

        // Ordenar por costoDiff ASC (mayor faltante primero)
        $filasOrd = $filas;
        usort($filasOrd, fn ($a, $b) => ($a['costoDiff'] ?? 0) <=> ($b['costoDiff'] ?? 0));

        // ── 6. Generar Excel ───────────────────────────────────────────────────
        $ss = new Spreadsheet();
        $ss->getProperties()->setCreator('Sistema Cadejo');

        $this->hojaResumenEjecutivo($ss, $sucursalName, $fecha, $filas, $nFalt, $nSobr, $nOk, $nSinBril, $nConBril, $totalCostoFalt, $totalCostoSobr);
        $this->hojaDetalleVsBrilo($ss, $filasOrd);
        $this->hojaEstadisticas($ss, $filas, $nFalt, $nSobr, $nOk, $nSinBril, $totalCostoFalt, $totalCostoSobr);

        $ss->setActiveSheetIndex(0);

        $filename = 'reporte_conteo_' . strtolower(str_replace([' ', '/'], '_', $sucursal ?? $sucursalId)) . '_' . $fecha . '.xlsx';

        return response()->stream(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // HOJA 1 — RESUMEN EJECUTIVO
    // ══════════════════════════════════════════════════════════════════════════
    private function hojaResumenEjecutivo(
        Spreadsheet $ss, string $sucursalName, string $fecha, array $filas,
        int $nFalt, int $nSobr, int $nOk, int $nSinBril, int $nConBril,
        float $totalCostoFalt, float $totalCostoSobr
    ): void {
        $ws = $ss->getActiveSheet();
        $ws->setTitle('Resumen Ejecutivo');
        $ws->setShowGridlines(false);
        $ws->getTabColor()->setRGB(self::C['amber']);
        $ws->getDefaultRowDimension()->setRowHeight(18);

        // Título
        $ws->mergeCells('A1:J1');
        $this->sc($ws, 1, 1, "REPORTE DE CONTEO FÍSICO — {$sucursalName}", [
            'bg' => self::C['header'], 'fg' => self::C['white'], 'size' => 16, 'bold' => true, 'align' => 'center',
        ]);
        $ws->getRowDimension(1)->setRowHeight(38);

        // Subtítulo
        $ws->mergeCells('A2:J2');
        $fecha_fmt = date('d/m/Y', strtotime($fecha));
        $this->sc($ws, 2, 1, "Fecha: {$fecha_fmt}   |   Generado: " . now()->format('d/m/Y H:i') . "   |   Sucursal: {$sucursalName}", [
            'bg' => 'ECEFF1', 'fg' => self::C['gray'], 'size' => 10, 'italic' => true, 'align' => 'center',
        ]);
        $ws->getRowDimension(2)->setRowHeight(22);
        $ws->getRowDimension(3)->setRowHeight(8);

        // KPI cards — filas 4-6
        $kpis = [
            [1, 'CONTADOS',       count($filas), 'productos con conteo',
             self::C['header'], self::C['amber']],
            [3, 'OK vs BRILO',    $nOk, ($nConBril ? round($nOk / $nConBril * 100) : 0) . '% coinciden',
             self::C['green'], self::C['white']],
            [5, 'FALTANTES',      $nFalt, '$' . number_format($totalCostoFalt, 2) . ' en pérdida',
             self::C['red'], self::C['white']],
            [7, 'SOBRANTES',      $nSobr, '$' . number_format($totalCostoSobr, 2) . ' de exceso',
             self::C['blue'], self::C['white']],
            [9, 'SIN DATO BRILO', $nSinBril, 'sin referencia BRILO',
             self::C['gray'], self::C['white']],
        ];

        foreach ($kpis as [$col, $label, $value, $sub, $bg, $fg]) {
            $c1 = Coordinate::stringFromColumnIndex($col);
            $c2 = Coordinate::stringFromColumnIndex($col + 1);
            $ws->mergeCells("{$c1}4:{$c2}4");
            $ws->mergeCells("{$c1}5:{$c2}5");
            $ws->mergeCells("{$c1}6:{$c2}6");
            foreach ([[4, $label, 9, false], [5, $value, 22, false], [6, $sub, 8, true]] as [$r, $v, $sz, $it]) {
                $this->sc($ws, $r, $col, $v, [
                    'bg' => $bg, 'fg' => $fg, 'size' => $sz, 'bold' => !$it, 'italic' => $it, 'align' => 'center',
                    'border_all' => $fg,
                ]);
            }
            $ws->getRowDimension(4)->setRowHeight(16);
            $ws->getRowDimension(5)->setRowHeight(32);
            $ws->getRowDimension(6)->setRowHeight(16);
        }

        $ws->getRowDimension(7)->setRowHeight(10);

        // Tabla por sección
        $hdrSec = ['Sección', '# Productos', 'Total Contado', 'Total BRILO', 'Diferencia', 'Estado'];
        foreach ($hdrSec as $ci => $h) {
            $this->sc($ws, 8, $ci + 1, $h, [
                'bg' => self::C['subhdr'], 'fg' => self::C['white'], 'bold' => true, 'align' => 'center',
                'border_bottom' => self::C['amber'],
            ]);
        }
        $ws->getRowDimension(8)->setRowHeight(22);

        $sr = 9;
        foreach (self::SECCIONES as $sec) {
            $ps = array_filter($filas, fn ($f) => ($f['secciones'][$sec] ?? 0) > 0);
            $tc = round(array_sum(array_column($ps, 'secciones' /* workaround below */)), 4);
            // Calcular tc manualmente
            $tc = 0; foreach ($ps as $f) $tc += (float)($f['secciones'][$sec] ?? 0);
            $tc = round($tc, 4);
            $hasBrilo = !empty(array_filter($ps, fn ($f) => $f['brilo'] !== null));
            $tb = 0; foreach (array_filter($ps, fn ($f) => $f['brilo'] !== null) as $f) $tb += $f['brilo'];
            $tb = round($tb, 4);
            $diffSec = $hasBrilo ? round($tc - $tb, 4) : null;
            $est = $diffSec === null ? 'SIN BRILO' : (abs($diffSec) < 0.5 ? 'OK' : ($diffSec < 0 ? 'FALTANTE' : 'SOBRANTE'));
            [$colBg, $colFg] = $this->tipoColor($est);
            $rowBg = $sr % 2 === 0 ? 'F9F9F9' : 'FFFFFF';

            $vals = [$sec, count($ps), $tc, $hasBrilo ? $tb : null, $diffSec, $est];
            foreach ($vals as $ci => $v) {
                $isE = $ci === 5; $isD = $ci === 4;
                $this->sc($ws, $sr, $ci + 1, $v, [
                    'bg'    => ($isE ? $colBg : $rowBg),
                    'fg'    => ($isE || $isD ? $colFg : null),
                    'bold'  => $isE || $isD,
                    'size'  => 10,
                    'align' => $ci === 0 ? 'left' : 'center',
                    'border_bottom' => 'EEEEEE',
                    'numfmt' => (is_float($v) ? '#,##0.00' : null),
                ]);
            }
            $ws->getRowDimension($sr)->setRowHeight(20);
            $sr++;
        }

        foreach ([18, 14, 18, 16, 16, 14, 5, 5, 5, 5] as $i => $w) {
            $ws->getColumnDimensionByColumn($i + 1)->setWidth($w);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // HOJA 2 — DETALLE VS BRILO
    // ══════════════════════════════════════════════════════════════════════════
    private function hojaDetalleVsBrilo(Spreadsheet $ss, array $filasOrd): void
    {
        $ws = $ss->createSheet();
        $ws->setTitle('Detalle vs BRILO');
        $ws->setShowGridlines(false);
        $ws->getTabColor()->setRGB('EF5350');
        $ws->getDefaultRowDimension()->setRowHeight(17);
        $ws->freezePane('D3');

        // Fila 1 — grupos
        $grupos = [
            ['IDENTIFICACIÓN',    5],
            ['COMPARATIVA BRILO', 6],
            ['',                  2],
        ];
        $gc = 1;
        foreach ($grupos as [$label, $cols]) {
            if ($cols > 1) $ws->mergeCells(Coordinate::stringFromColumnIndex($gc) . '1:' . Coordinate::stringFromColumnIndex($gc + $cols - 1) . '1');
            $this->sc($ws, 1, $gc, $label, ['bg' => self::C['header'], 'fg' => self::C['white'], 'bold' => true, 'align' => 'center']);
            $gc += $cols;
        }
        $ws->getRowDimension(1)->setRowHeight(24);

        // Fila 2 — columnas
        $cols2 = ['Sucursal','Código','Nombre','Categoría','Unidad','Stock BRILO','Conteo Físico','Diferencia','Dif. %','Tipo','Costo Unit.','Costo Dif. ($)','Comentarios'];
        foreach ($cols2 as $ci => $h) {
            $this->sc($ws, 2, $ci + 1, $h, [
                'bg' => self::C['subhdr'], 'fg' => self::C['white'], 'bold' => true, 'align' => 'center', 'wrap' => true,
            ]);
        }
        $ws->getRowDimension(2)->setRowHeight(30);

        $dr = 3;
        foreach ($filasOrd as $f) {
            [$colBg, $colFg] = $this->tipoColor($f['tipo']);
            $rowBg = $dr % 2 === 0 ? 'F8F8F8' : 'FFFFFF';

            $vals = [
                $f['sucursal'], $f['codigo'], $f['nombre'], $f['categoria'], $f['unidad'],
                $f['brilo'], $f['conteo'], $f['diff'], $f['diffPct'], $f['tipo'],
                $f['costo'], $f['costoDiff'], '',
            ];

            foreach ($vals as $ci => $v) {
                $isTipo  = $ci === 9;
                $isDiff  = $ci === 7;
                $isCostD = $ci === 11;
                $bg = $isTipo ? $colBg : ($isCostD && $f['costoDiff'] !== null ? ($f['tipo'] === 'FALTANTE' ? self::C['red_bg'] : ($f['tipo'] === 'SOBRANTE' ? self::C['blue_bg'] : $rowBg)) : $rowBg);
                $fg = ($isTipo || $isDiff || $isCostD) ? $colFg : null;
                $numfmt = null;
                if (is_float($v) && $ci >= 5 && $ci !== 8) $numfmt = '#,##0.00';
                if ($ci === 8 && $v !== null)               $numfmt = '#,##0.00"%"';
                if ($ci === 11 && $v !== null)              $numfmt = '"$"#,##0.00';

                $this->sc($ws, $dr, $ci + 1, $v, [
                    'bg' => $bg, 'fg' => $fg,
                    'bold' => $isTipo || $isDiff || $isCostD,
                    'size' => 9,
                    'align' => $ci < 5 ? 'left' : 'center',
                    'border_bottom' => 'DDDDDD',
                    'numfmt' => $numfmt,
                ]);
            }
            $ws->getRowDimension($dr)->setRowHeight(17);
            $dr++;
        }

        foreach ([14,14,38,22,8,14,14,13,10,12,13,16,26] as $i => $w) {
            $ws->getColumnDimensionByColumn($i + 1)->setWidth($w);
        }
        $ws->setAutoFilter("A2:" . Coordinate::stringFromColumnIndex(count($cols2)) . "2");
    }

    // ══════════════════════════════════════════════════════════════════════════
    // HOJA 3 — ESTADÍSTICAS
    // ══════════════════════════════════════════════════════════════════════════
    private function hojaEstadisticas(
        Spreadsheet $ss, array $filas, int $nFalt, int $nSobr, int $nOk, int $nSinBril,
        float $totalCostoFalt, float $totalCostoSobr
    ): void {
        $ws = $ss->createSheet();
        $ws->setTitle('Estadísticas');
        $ws->setShowGridlines(false);
        $ws->getTabColor()->setRGB(self::C['blue']);
        $ws->getDefaultRowDimension()->setRowHeight(18);

        $ST_COLS = 8;
        $total   = count($filas);

        // ── Tabla 1: Resumen de estados ──────────────────────────────────────
        $this->secTitle($ws, 1, '1.  Resumen — Conteo vs BRILO', $ST_COLS);
        $tabla1 = [
            ['Estado', 'Cantidad', '% del total'],
            ['OK',        $nOk,     $total ? round($nOk / $total * 100, 2) : 0],
            ['FALTANTE',  $nFalt,   $total ? round($nFalt / $total * 100, 2) : 0],
            ['SOBRANTE',  $nSobr,   $total ? round($nSobr / $total * 100, 2) : 0],
            ['SIN BRILO', $nSinBril,$total ? round($nSinBril / $total * 100, 2) : 0],
            ['TOTAL',     $total,   100],
        ];
        foreach ($tabla1 as $ri => $row) {
            $isHdr   = $ri === 0;
            $isTotal = $ri === count($tabla1) - 1;
            [$colBg, $colFg] = $isHdr ? [self::C['subhdr'], self::C['white']] : $this->tipoColor($row[0]);
            foreach ($row as $ci => $v) {
                $this->sc($ws, 2 + $ri, $ci + 1, $v, [
                    'bold'   => $isHdr || $isTotal || $ci > 0,
                    'size'   => $isHdr ? 9 : 10,
                    'fg'     => ($isHdr || ($ci > 0 && !$isTotal)) ? $colFg : null,
                    'bg'     => $isHdr ? $colBg : ($ci > 0 && !$isTotal ? $colBg : 'FFFFFF'),
                    'align'  => $ci === 0 ? 'left' : 'center',
                    'border_bottom' => 'EEEEEE',
                    'numfmt' => ($ci === 2 ? '#,##0.00"%"' : null),
                ]);
            }
            $ws->getRowDimension(2 + $ri)->setRowHeight(20);
        }

        // ── Tabla 2: Por sección ─────────────────────────────────────────────
        $st2 = 10;
        $this->secTitle($ws, $st2, '2.  Conteo por sección', $ST_COLS);
        $tabla2 = [['Sección', '# Productos', 'Total Contado', 'Total BRILO', 'Diferencia']];
        foreach (self::SECCIONES as $sec) {
            $ps = array_filter($filas, fn ($f) => ($f['secciones'][$sec] ?? 0) > 0);
            $tc = 0; foreach ($ps as $f) $tc += (float)($f['secciones'][$sec] ?? 0);
            $hasBrilo = !empty(array_filter($ps, fn ($f) => $f['brilo'] !== null));
            $tb = 0; foreach (array_filter($ps, fn ($f) => $f['brilo'] !== null) as $f) $tb += $f['brilo'];
            $tabla2[] = [$sec, count($ps), round($tc, 4), $hasBrilo ? round($tb, 4) : null, $hasBrilo ? round($tc - $tb, 4) : null];
        }
        foreach ($tabla2 as $ri => $row) {
            $isHdr = $ri === 0;
            foreach ($row as $ci => $v) {
                $this->sc($ws, $st2 + 1 + $ri, $ci + 1, $v, [
                    'bold'   => $isHdr,
                    'size'   => 9,
                    'fg'     => $isHdr ? self::C['white'] : null,
                    'bg'     => $isHdr ? self::C['subhdr'] : ($ri % 2 === 0 ? 'FFFFFF' : 'F5F5F5'),
                    'align'  => $ci === 0 ? 'left' : 'center',
                    'border_bottom' => 'EEEEEE',
                    'numfmt' => ($ci >= 2 && is_float($v) ? '#,##0.00' : null),
                ]);
            }
            $ws->getRowDimension($st2 + 1 + $ri)->setRowHeight(20);
        }

        // ── Tabla 3: Top 10 faltantes ────────────────────────────────────────
        $st3 = $st2 + 1 + count(self::SECCIONES) + 2;
        $this->secTitle($ws, $st3, '3.  Top 10 mayores faltantes vs BRILO (por costo $)', $ST_COLS);
        $top10Falt = array_slice(
            array_filter($filas, fn ($f) => $f['tipo'] === 'FALTANTE'),
            0
        );
        usort($top10Falt, fn ($a, $b) => ($a['costoDiff'] ?? 0) <=> ($b['costoDiff'] ?? 0));
        $top10Falt = array_slice($top10Falt, 0, 10);

        $hdrTop = ['#', 'Código', 'Nombre', 'Conteo Físico', 'Stock BRILO', 'Diferencia', 'Costo Unit.', 'Costo Dif. ($)'];
        $rows3  = [$hdrTop];
        foreach ($top10Falt as $i => $f) {
            $rows3[] = [$i + 1, $f['codigo'], $f['nombre'], $f['conteo'], $f['brilo'], $f['diff'], $f['costo'], $f['costoDiff']];
        }
        foreach ($rows3 as $ri => $row) {
            $isHdr = $ri === 0;
            foreach ($row as $ci => $v) {
                $isDiff = !$isHdr && $ci === 5;
                $isCost = !$isHdr && $ci === 7;
                $this->sc($ws, $st3 + 1 + $ri, $ci + 1, $v, [
                    'bold'   => $isHdr || $isDiff || $isCost,
                    'size'   => 9,
                    'fg'     => $isHdr ? self::C['white'] : (($isDiff || $isCost) ? self::C['red'] : null),
                    'bg'     => $isHdr ? self::C['subhdr'] : (($isDiff || $isCost) ? self::C['red_bg'] : ($ri % 2 === 0 ? 'FFFFFF' : 'F5F5F5')),
                    'align'  => $ci === 2 ? 'left' : 'center',
                    'border_bottom' => 'EEEEEE',
                    'numfmt' => ($ci >= 3 && is_float($v) && !$isHdr ? '#,##0.00' : null) ?: ($ci === 7 && !$isHdr && $v !== null ? '"$"#,##0.00' : null),
                ]);
            }
            $ws->getRowDimension($st3 + 1 + $ri)->setRowHeight(18);
        }
        // Total faltantes
        $totFaltRow = $st3 + 1 + count($rows3) + 1;
        $ws->mergeCells('A' . $totFaltRow . ':F' . $totFaltRow);
        $this->sc($ws, $totFaltRow, 1, 'Total pérdida estimada (Top ' . count($top10Falt) . ' faltantes):', [
            'bold' => true, 'size' => 9, 'fg' => self::C['red'], 'align' => 'right',
        ]);
        $this->sc($ws, $totFaltRow, 8, $totalCostoFalt, [
            'bold' => true, 'size' => 10, 'fg' => self::C['red'], 'bg' => self::C['red_bg'],
            'align' => 'center', 'numfmt' => '"$"#,##0.00',
        ]);
        $ws->getRowDimension($totFaltRow)->setRowHeight(20);

        // ── Tabla 4: Top 10 sobrantes ────────────────────────────────────────
        $st4 = $totFaltRow + 2;
        $this->secTitle($ws, $st4, '4.  Top 10 mayores sobrantes vs BRILO (por costo $)', $ST_COLS);
        $top10Sobr = array_filter($filas, fn ($f) => $f['tipo'] === 'SOBRANTE');
        usort($top10Sobr, fn ($a, $b) => ($b['costoDiff'] ?? 0) <=> ($a['costoDiff'] ?? 0));
        $top10Sobr = array_slice($top10Sobr, 0, 10);

        $rows4 = [$hdrTop];
        foreach ($top10Sobr as $i => $f) {
            $rows4[] = [$i + 1, $f['codigo'], $f['nombre'], $f['conteo'], $f['brilo'], $f['diff'], $f['costo'], $f['costoDiff']];
        }
        foreach ($rows4 as $ri => $row) {
            $isHdr = $ri === 0;
            foreach ($row as $ci => $v) {
                $isDiff = !$isHdr && $ci === 5;
                $isCost = !$isHdr && $ci === 7;
                $this->sc($ws, $st4 + 1 + $ri, $ci + 1, $v, [
                    'bold'   => $isHdr || $isDiff || $isCost,
                    'size'   => 9,
                    'fg'     => $isHdr ? self::C['white'] : (($isDiff || $isCost) ? self::C['blue'] : null),
                    'bg'     => $isHdr ? self::C['subhdr'] : (($isDiff || $isCost) ? self::C['blue_bg'] : ($ri % 2 === 0 ? 'FFFFFF' : 'F5F5F5')),
                    'align'  => $ci === 2 ? 'left' : 'center',
                    'border_bottom' => 'EEEEEE',
                    'numfmt' => ($ci >= 3 && is_float($v) && !$isHdr ? '#,##0.00' : null) ?: ($ci === 7 && !$isHdr && $v !== null ? '"$"#,##0.00' : null),
                ]);
            }
            $ws->getRowDimension($st4 + 1 + $ri)->setRowHeight(18);
        }
        if (count($top10Sobr) > 0) {
            $totSobrRow = $st4 + 1 + count($rows4) + 1;
            $ws->mergeCells('A' . $totSobrRow . ':F' . $totSobrRow);
            $this->sc($ws, $totSobrRow, 1, 'Total sobrante estimado (Top ' . count($top10Sobr) . ' sobrantes):', [
                'bold' => true, 'size' => 9, 'fg' => self::C['blue'], 'align' => 'right',
            ]);
            $this->sc($ws, $totSobrRow, 8, $totalCostoSobr, [
                'bold' => true, 'size' => 10, 'fg' => self::C['blue'], 'bg' => self::C['blue_bg'],
                'align' => 'center', 'numfmt' => '"$"#,##0.00',
            ]);
            $ws->getRowDimension($totSobrRow)->setRowHeight(20);
        }

        foreach ([6, 14, 36, 15, 15, 14, 13, 16] as $i => $w) {
            $ws->getColumnDimensionByColumn($i + 1)->setWidth($w);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function tipoColor(string $tipo): array
    {
        return match ($tipo) {
            'OK'       => [self::C['green_bg'], self::C['green']],
            'FALTANTE' => [self::C['red_bg'],   self::C['red']],
            'SOBRANTE' => [self::C['blue_bg'],  self::C['blue']],
            default    => [self::C['gray_bg'],  self::C['gray']],
        };
    }

    private function secTitle($ws, int $row, string $text, int $cols): void
    {
        $ws->mergeCells('A' . $row . ':' . Coordinate::stringFromColumnIndex($cols) . $row);
        $this->sc($ws, $row, 1, $text, [
            'bg' => self::C['header'], 'fg' => self::C['white'], 'bold' => true, 'size' => 11, 'align' => 'left',
        ]);
        $ws->getRowDimension($row)->setRowHeight(22);
    }

    /**
     * Set cell value + style.
     * opts: bg, fg, bold, size, italic, align, wrap, border_bottom, border_all, numfmt
     */
    private function sc($ws, int $row, int $col, mixed $value, array $opts = []): void
    {
        $coord = Coordinate::stringFromColumnIndex($col) . $row;
        $cell  = $ws->getCell($coord);
        $cell->setValue($value);
        $style = $ws->getStyle($coord);

        $arr = ['alignment' => ['vertical' => 'middle']];

        if (!empty($opts['bg'])) {
            $arr['fill'] = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $opts['bg']]];
        }
        if (!empty($opts['fg']) || !empty($opts['bold']) || !empty($opts['size']) || !empty($opts['italic'])) {
            $arr['font'] = [];
            if (!empty($opts['bold']))   $arr['font']['bold']   = true;
            if (!empty($opts['size']))   $arr['font']['size']   = $opts['size'];
            if (!empty($opts['italic'])) $arr['font']['italic'] = true;
            if (!empty($opts['fg']))     $arr['font']['color']  = ['argb' => 'FF' . $opts['fg']];
        }
        if (!empty($opts['align'])) $arr['alignment']['horizontal'] = $opts['align'];
        if (!empty($opts['wrap']))  $arr['alignment']['wrapText']    = true;

        if (!empty($opts['border_bottom'])) {
            $arr['borders']['bottom'] = ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF' . $opts['border_bottom']]];
        }
        if (!empty($opts['border_all'])) {
            foreach (['top','bottom','left','right'] as $side) {
                $arr['borders'][$side] = ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF' . $opts['border_all']]];
            }
        }

        $style->applyFromArray($arr);

        if (!empty($opts['numfmt'])) {
            $style->getNumberFormat()->setFormatCode($opts['numfmt']);
        }
    }
}
