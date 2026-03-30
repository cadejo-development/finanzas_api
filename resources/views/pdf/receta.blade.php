<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
/* ── PÁGINA ── */
@page {
  margin: 18mm 16mm 16mm 16mm;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 10px;
  color: #1e1e1e;
  background: #fff;
  line-height: 1.45;
}

/* ── ACCENT BAR SUPERIOR ── */
.accent-bar {
  height: 5px;
  background: #1a1a1a;
  margin-bottom: 14px;
}
.accent-inner {
  height: 100%;
  width: 60px;
  background: #c49a28;
  display: inline-block;
}

/* ── HEADER ── */
.header {
  display: table;
  width: 100%;
  margin-bottom: 6px;
}
.header-left  { display: table-cell; vertical-align: top; }
.header-right { display: table-cell; vertical-align: top; text-align: right; white-space: nowrap; padding-left: 12px; }

.company-name {
  font-size: 9px;
  font-weight: bold;
  text-transform: uppercase;
  letter-spacing: 2px;
  color: #888;
}
.doc-type {
  font-size: 7.5px;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: #bbb;
  margin-top: 1px;
}
.header-fecha {
  font-size: 9px;
  color: #666;
  margin-top: 2px;
}

.recipe-name {
  font-size: 20px;
  font-weight: bold;
  color: #1a1a1a;
  letter-spacing: -0.3px;
  margin-top: 10px;
  margin-bottom: 3px;
  line-height: 1.2;
  text-transform: uppercase;
}
.recipe-breadcrumb {
  font-size: 8.5px;
  color: #999;
  letter-spacing: 0.5px;
  margin-bottom: 14px;
}
.recipe-breadcrumb span {
  color: #c49a28;
  font-weight: bold;
}

.header-divider {
  border: none;
  border-top: 1.5px solid #1a1a1a;
  margin-bottom: 18px;
}

/* ── SECCIONES ── */
.section { margin-bottom: 18px; }

.section-title {
  font-size: 7.5px;
  font-weight: bold;
  text-transform: uppercase;
  letter-spacing: 1.8px;
  color: #fff;
  background: #1a1a1a;
  padding: 4px 9px;
  margin-bottom: 0;
  display: inline-block;
}
.section-body {
  border: 1px solid #e0e0e0;
  border-top: none;
  padding: 12px;
}

/* ── RESUMEN ECONÓMICO ── */
.kpi-row { display: table; width: 100%; border-collapse: collapse; }
.kpi-cell {
  display: table-cell;
  text-align: center;
  padding: 10px 8px;
  border-right: 1px solid #e0e0e0;
  vertical-align: middle;
}
.kpi-cell:last-child { border-right: none; }
.kpi-cell.highlight  { background: #fafafa; }
.kpi-label {
  font-size: 7px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #999;
  margin-bottom: 4px;
}
.kpi-value {
  font-size: 17px;
  font-weight: bold;
  color: #1a1a1a;
}
.kpi-value-sm {
  font-size: 12px;
  font-weight: bold;
  color: #444;
}
.kpi-sub {
  font-size: 7.5px;
  color: #aaa;
  margin-top: 2px;
}
.kpi-accent { color: #c49a28; }

/* ── INFORMACIÓN GENERAL ── */
.info-table { width: 100%; border-collapse: collapse; }
.info-table tr { border-bottom: 1px solid #f0f0f0; }
.info-table tr:last-child { border-bottom: none; }
.info-table td { padding: 5px 0; font-size: 10px; vertical-align: top; }
.info-label {
  color: #999;
  width: 30%;
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.info-value { color: #1a1a1a; font-weight: bold; }

/* ── TABLAS DE DATOS ── */
.data-table { width: 100%; border-collapse: collapse; }
.data-table thead tr { background: #2c2c2c; }
.data-table thead th {
  padding: 7px 10px;
  text-align: left;
  font-size: 7.5px;
  font-weight: bold;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  color: #e8e8e8;
}
.data-table thead th.right { text-align: right; }
.data-table tbody tr { border-bottom: 1px solid #ececec; }
.data-table tbody tr:nth-child(even) { background: #f9f9f9; }
.data-table tbody td {
  padding: 5px 10px;
  font-size: 10px;
  vertical-align: middle;
  color: #2a2a2a;
}
.data-table tbody td.right { text-align: right; }
.data-table tbody td.muted { color: #999; font-size: 9px; }

.data-table tfoot tr {
  background: #1a1a1a;
  border-top: 2px solid #c49a28;
}
.data-table tfoot td {
  padding: 6px 10px;
  font-size: 10px;
  font-weight: bold;
  color: #fff;
}
.data-table tfoot td.right { text-align: right; color: #c49a28; }

/* tags */
.tag-sub {
  display: inline-block;
  background: #e8e8e8;
  color: #555;
  border-radius: 2px;
  padding: 1px 5px;
  font-size: 7px;
  font-weight: bold;
  margin-left: 5px;
  vertical-align: middle;
  letter-spacing: 0.5px;
}
.grupo-num {
  display: inline-block;
  background: #c49a28;
  color: #fff;
  border-radius: 2px;
  padding: 1px 6px;
  font-size: 7px;
  font-weight: bold;
  margin-right: 5px;
  vertical-align: middle;
}
.costo-zero { color: #ccc; }
.costo-ok   { color: #1a1a1a; }

/* ── INSTRUCCIONES ── */
.instrucciones {
  font-size: 10.5px;
  line-height: 1.9;
  color: #2a2a2a;
  white-space: pre-wrap;
}

/* ── FOTOS ── */
.foto-caption {
  font-size: 7.5px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #999;
  margin-bottom: 5px;
}
.foto-img {
  width: 100%;
  max-height: 200px;
  object-fit: cover;
  border: 1px solid #ddd;
  display: block;
}

/* ── FOOTER ── */
.footer {
  margin-top: 28px;
  border-top: 1px solid #ddd;
  padding-top: 7px;
  font-size: 7.5px;
  color: #bbb;
  display: table;
  width: 100%;
}
.footer-left  { display: table-cell; }
.footer-right { display: table-cell; text-align: right; white-space: nowrap; }
.footer-accent { color: #c49a28; font-weight: bold; }
</style>
</head>
<body>

{{-- ── BARRA DE ACENTO ── --}}
<div class="accent-bar"><div class="accent-inner"></div></div>

{{-- ── HEADER ── --}}
<div class="header">
  <div class="header-left">
    <div class="company-name">Cadejo Brewing Company</div>
    <div class="doc-type">Ficha Técnica de Receta</div>
  </div>
  <div class="header-right">
    <div class="header-fecha">{{ \Carbon\Carbon::now('America/El_Salvador')->format('d/m/Y') }}</div>
  </div>
</div>

<div class="recipe-name">{{ $receta['nombre'] }}</div>
<div class="recipe-breadcrumb">
  <span>{{ $receta['categoria'] ?? $receta['tipo'] ?? '' }}</span>
  &nbsp;·&nbsp;
  {{ $receta['tipo_receta'] === 'sub_receta' ? 'Sub-Receta' : 'Receta' }}
</div>

<hr class="header-divider">

{{-- ── CONTENIDO ── --}}
@php
  $precioVenta   = (float) ($receta['precio'] ?? 0);
  $costoIngr     = $costo_total;
  $modificadores = $receta['modificadores'] ?? [];
  $costoMods = collect($modificadores)->sum(function($g) {
      return collect($g['opciones'] ?? [])->avg('costo_total') ?? 0;
  });
  $costoTotal = $costoIngr + $costoMods;
  $pctCosto   = ($precioVenta > 0) ? (($costoTotal / $precioVenta) * 100) : null;
  $margen     = ($precioVenta > 0) ? (($precioVenta - $costoTotal) / $precioVenta * 100) : null;
@endphp

{{-- ── RESUMEN ECONÓMICO ── --}}
<div class="section">
  <div class="section-title">Resumen Económico</div>
  <div class="section-body" style="padding:0;">
    <div class="kpi-row">
      <div class="kpi-cell highlight">
        <div class="kpi-label">Costo ingredientes</div>
        <div class="kpi-value">${{ number_format($costoIngr, 2) }}</div>
      </div>
      @if(count($modificadores) > 0)
      <div class="kpi-cell">
        <div class="kpi-label">Costo modificadores (prom.)</div>
        <div class="kpi-value">${{ number_format($costoMods, 2) }}</div>
      </div>
      <div class="kpi-cell highlight">
        <div class="kpi-label">Costo total estimado</div>
        <div class="kpi-value">${{ number_format($costoTotal, 2) }}</div>
      </div>
      @endif
      <div class="kpi-cell">
        <div class="kpi-label">Precio de venta</div>
        @if($precioVenta > 0)
          <div class="kpi-value">${{ number_format($precioVenta, 2) }}</div>
        @else
          <div class="kpi-value-sm" style="color:#ccc;">No definido</div>
        @endif
      </div>
      <div class="kpi-cell highlight">
        <div class="kpi-label">% Costo / Margen</div>
        @if($pctCosto !== null)
          <div class="kpi-value kpi-accent">{{ number_format($pctCosto, 1) }}%</div>
          <div class="kpi-sub">Margen {{ number_format($margen, 1) }}%</div>
        @else
          <div class="kpi-value-sm" style="color:#ccc;">—</div>
        @endif
      </div>
    </div>
  </div>
</div>

{{-- ── INFORMACIÓN GENERAL ── --}}
<div class="section">
  <div class="section-title">Información General</div>
  <div class="section-body">
    <table class="info-table">
      <tr>
        <td class="info-label">Tipo de receta</td>
        <td class="info-value">{{ $receta['tipo_receta'] === 'sub_receta' ? 'Sub-Receta' : 'Plato' }}</td>
      </tr>
      <tr>
        <td class="info-label">Categoría</td>
        <td class="info-value">{{ $receta['categoria'] ?? $receta['tipo'] ?? '—' }}</td>
      </tr>
      @if($receta['rendimiento'])
      <tr>
        <td class="info-label">Rendimiento</td>
        <td class="info-value">{{ number_format($receta['rendimiento'], 2) }} {{ $receta['rendimiento_unidad'] ?? '' }}</td>
      </tr>
      @endif
      @if(($receta['platos_semana'] ?? 0) > 0)
      <tr>
        <td class="info-label">Platos / semana</td>
        <td class="info-value">{{ $receta['platos_semana'] }}</td>
      </tr>
      @endif
      @if($receta['descripcion'])
      <tr>
        <td class="info-label">Descripción</td>
        <td class="info-value" style="font-weight:normal; color:#444;">{{ $receta['descripcion'] }}</td>
      </tr>
      @endif
    </table>
  </div>
</div>

{{-- ── INGREDIENTES ── --}}
<div class="section">
  <div class="section-title">Ingredientes</div>
  <table class="data-table">
    <thead>
      <tr>
        <th width="4%">#</th>
        <th>Producto / Sub-Receta</th>
        <th class="right" width="14%">Cantidad</th>
        <th width="10%">Unidad</th>
        <th class="right" width="13%">Costo unit.</th>
        <th class="right" width="13%">Subtotal</th>
      </tr>
    </thead>
    <tbody>
      @foreach($receta['ingredientes'] as $i => $ing)
      @php
        $precioUnit = (float) ($ing['precio_unitario'] ?? 0);
        $cant       = (float) ($ing['cantidad_por_plato'] ?? 0);
        $subtotal   = $precioUnit * $cant;
      @endphp
      <tr>
        <td class="muted">{{ $i + 1 }}</td>
        <td>
          {{ $ing['producto_nombre'] ?? '—' }}
          @if($ing['es_sub_receta'])<span class="tag-sub">SUB</span>@endif
        </td>
        <td class="right">{{ number_format($cant, 4) }}</td>
        <td class="muted">{{ $ing['unidad'] }}</td>
        <td class="right {{ $precioUnit > 0 ? 'costo-ok' : 'costo-zero' }}">
          ${{ number_format($precioUnit, 4) }}
        </td>
        <td class="right {{ $subtotal > 0 ? 'costo-ok' : 'costo-zero' }}">
          ${{ number_format($subtotal, 2) }}
        </td>
      </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <td colspan="4">Total &nbsp;·&nbsp; {{ count($receta['ingredientes']) }} línea(s)</td>
        <td></td>
        <td class="right">${{ number_format($costo_total, 2) }}</td>
      </tr>
    </tfoot>
  </table>
</div>

{{-- ── MODIFICADORES ── --}}
@if(count($modificadores) > 0)
<div class="section">
  <div class="section-title">Modificadores del Menú &nbsp;·&nbsp; {{ count($modificadores) }} grupo{{ count($modificadores) > 1 ? 's' : '' }}</div>
  <table class="data-table">
    <thead>
      <tr>
        <th width="28%">Grupo</th>
        <th>Opción</th>
        <th class="right" width="13%">Cantidad</th>
        <th width="10%">Unidad</th>
        <th class="right" width="13%">Costo</th>
      </tr>
    </thead>
    <tbody>
      @foreach($modificadores as $gi => $grupo)
        @foreach($grupo['opciones'] as $j => $op)
        @php $costoOp = (float)($op['costo_total'] ?? 0); @endphp
        <tr>
          <td style="font-size:9.5px;">
            @if($j === 0)
              <span class="grupo-num">{{ $gi + 1 }}</span>{{ $grupo['grupo_nombre'] }}
            @endif
          </td>
          <td>{{ $op['nombre'] }}</td>
          <td class="right">{{ number_format((float)($op['cantidad'] ?? 0), 4) }}</td>
          <td class="muted">{{ $op['unidad'] ?? '' }}</td>
          <td class="right {{ $costoOp > 0 ? 'costo-ok' : 'costo-zero' }}">
            ${{ number_format($costoOp, 4) }}
          </td>
        </tr>
        @endforeach
      @endforeach
    </tbody>
  </table>
</div>
@endif

{{-- ── INSTRUCCIONES ── --}}
@if($receta['instrucciones'])
<div class="section">
  <div class="section-title">Instrucciones de Preparación</div>
  <div class="section-body">
    <div class="instrucciones">{{ $receta['instrucciones'] }}</div>
  </div>
</div>
@endif

{{-- ── FOTOGRAFÍAS (al final) ── --}}
@if($foto_plato || $foto_plateria)
<div class="section">
  <div class="section-title">Fotografías de Referencia</div>
  <div class="section-body">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        @if($foto_plato)
        <td style="padding-right:{{ $foto_plateria ? '10px' : '0' }}; vertical-align:top; width:{{ $foto_plateria ? '50%' : '65%' }};">
          <div class="foto-caption">Presentación del plato</div>
          <img src="{{ $foto_plato }}" class="foto-img" alt="Foto del plato" />
        </td>
        @endif
        @if($foto_plateria)
        <td style="padding-left:{{ $foto_plato ? '10px' : '0' }}; vertical-align:top; width:{{ $foto_plato ? '50%' : '65%' }};">
          <div class="foto-caption">Loza / Montaje</div>
          <img src="{{ $foto_plateria }}" class="foto-img" alt="Foto de la loza" />
        </td>
        @endif
      </tr>
    </table>
  </div>
</div>
@endif

{{-- ── FOOTER ── --}}
<div class="footer">
  <div class="footer-left">
    <span class="footer-accent">Cadejo Brewing Company</span>
    &nbsp;·&nbsp; Documento interno — uso exclusivo del equipo de operaciones
  </div>
  <div class="footer-right">Generado el {{ \Carbon\Carbon::now('America/El_Salvador')->format('d/m/Y \a\l\a\s H:i') }}</div>
</div>

</body>
</html>
