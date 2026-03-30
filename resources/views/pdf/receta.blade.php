<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
/* Sin @page — los márgenes los maneja el wrapper div para máxima compatibilidad con dompdf */
* { margin: 0; padding: 0; box-sizing: border-box; }

html, body {
  background: #fff;
  width: 100%;
}
body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 10px;
  color: #1e1e1e;
  line-height: 1.45;
}

/* ── WRAPPER CON MÁRGENES ── */
.wrap {
  padding: 16mm 17mm 14mm 17mm;
}

/* ── ACCENT BAR ── */
.accent-bar {
  width: 100%;
  height: 4px;
  background: #1a1a1a;
  margin-bottom: 12px;
  position: relative;
}
.accent-gold {
  position: absolute;
  top: 0; left: 0;
  height: 4px;
  width: 55px;
  background: #c49a28;
}

/* ── HEADER ── */
.header {
  display: table;
  width: 100%;
  margin-bottom: 8px;
}
.header-left  { display: table-cell; vertical-align: middle; width: 55px; }
.header-center { display: table-cell; vertical-align: middle; padding-left: 12px; }
.header-right {
  display: table-cell;
  vertical-align: middle;
  text-align: right;
  white-space: nowrap;
  padding-left: 10px;
}

.logo { width: 50px; height: auto; }

.company-name {
  font-size: 9px;
  font-weight: bold;
  text-transform: uppercase;
  letter-spacing: 2px;
  color: #555;
}
.doc-type {
  font-size: 7px;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: #bbb;
  margin-top: 2px;
}
.header-fecha {
  font-size: 8.5px;
  color: #888;
}

.recipe-name {
  font-size: 21px;
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
  color: #bbb;
  letter-spacing: 0.3px;
  margin-bottom: 12px;
}
.recipe-breadcrumb .cat {
  color: #c49a28;
  font-weight: bold;
}

.header-rule {
  border: none;
  border-top: 1.5px solid #1a1a1a;
  margin-bottom: 16px;
}

/* ── SECCIONES ── */
.section { margin-bottom: 16px; }

.section-head {
  background: #1a1a1a;
  color: #fff;
  padding: 5px 10px;
  font-size: 7px;
  font-weight: bold;
  letter-spacing: 1.8px;
  text-transform: uppercase;
}
.section-head .gold { color: #c49a28; }

.section-body {
  border: 1px solid #e0e0e0;
  border-top: none;
}
.section-body.padded { padding: 11px 12px; }

/* ── KPIs ── */
.kpi-row { display: table; width: 100%; }
.kpi-cell {
  display: table-cell;
  text-align: center;
  padding: 10px 8px;
  border-right: 1px solid #e8e8e8;
  vertical-align: middle;
}
.kpi-cell:last-child { border-right: none; }
.kpi-cell.alt { background: #fafafa; }
.kpi-lbl {
  font-size: 6.5px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #aaa;
  margin-bottom: 4px;
}
.kpi-val {
  font-size: 17px;
  font-weight: bold;
  color: #1a1a1a;
}
.kpi-val.gold { color: #c49a28; }
.kpi-val.muted { font-size: 11px; color: #ccc; }
.kpi-sub { font-size: 7px; color: #bbb; margin-top: 2px; }

/* ── INFO TABLE ── */
.info-tbl { width: 100%; border-collapse: collapse; }
.info-tbl tr { border-bottom: 1px solid #f2f2f2; }
.info-tbl tr:last-child { border-bottom: none; }
.info-tbl td { padding: 5px 0; font-size: 10px; vertical-align: top; }
.info-lbl { color: #aaa; width: 30%; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.5px; }
.info-val { color: #1a1a1a; font-weight: bold; }

/* ── TABLAS DATOS ── */
.dt { width: 100%; border-collapse: collapse; }
.dt thead tr { background: #2c2c2c; }
.dt thead th {
  padding: 7px 9px;
  text-align: left;
  font-size: 7px;
  font-weight: bold;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  color: #ddd;
}
.dt thead th.r { text-align: right; }
.dt tbody tr { border-bottom: 1px solid #ececec; }
.dt tbody tr:nth-child(even) { background: #f9f9f9; }
.dt tbody td {
  padding: 5px 9px;
  font-size: 10px;
  vertical-align: middle;
  color: #222;
}
.dt tbody td.r { text-align: right; }
.dt tbody td.sm { color: #aaa; font-size: 9px; }
.dt tfoot tr { background: #1a1a1a; border-top: 2px solid #c49a28; }
.dt tfoot td {
  padding: 6px 9px;
  font-size: 10px;
  font-weight: bold;
  color: #eee;
}
.dt tfoot td.r { text-align: right; color: #c49a28; }

/* tags */
.tag-sub {
  display: inline-block;
  background: #ebebeb;
  color: #666;
  border-radius: 2px;
  padding: 1px 4px;
  font-size: 6.5px;
  font-weight: bold;
  margin-left: 4px;
  vertical-align: middle;
  letter-spacing: 0.4px;
}
.grp-num {
  display: inline-block;
  background: #c49a28;
  color: #fff;
  border-radius: 2px;
  padding: 1px 5px;
  font-size: 6.5px;
  font-weight: bold;
  margin-right: 5px;
  vertical-align: middle;
}
.zero { color: #ddd; }

/* ── INSTRUCCIONES ── */
.instrucciones {
  font-size: 10.5px;
  line-height: 1.9;
  color: #333;
  white-space: pre-wrap;
}

/* ── FOTOS ── */
.foto-lbl {
  font-size: 7px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #aaa;
  margin-bottom: 5px;
}
.foto-img { width: 100%; max-height: 195px; object-fit: cover; border: 1px solid #ddd; display: block; }

/* ── FOOTER ── */
.footer {
  margin-top: 22px;
  border-top: 1px solid #e8e8e8;
  padding-top: 6px;
  font-size: 7px;
  color: #bbb;
  display: table;
  width: 100%;
}
.footer-l { display: table-cell; }
.footer-r { display: table-cell; text-align: right; white-space: nowrap; }
.footer-brand { color: #c49a28; font-weight: bold; }
</style>
</head>
<body>
<div class="wrap">

{{-- ACCENT BAR --}}
<div class="accent-bar"><div class="accent-gold"></div></div>

{{-- HEADER --}}
<div class="header">
  <div class="header-left">
    <img src="{{ public_path('images/cadejo_logo.png') }}" class="logo" alt="Cadejo" />
  </div>
  <div class="header-center">
    <div class="company-name">Cadejo Brewing Company</div>
    <div class="doc-type">Ficha Técnica de Receta</div>
  </div>
  <div class="header-right">
    <div class="header-fecha">{{ \Carbon\Carbon::now('America/El_Salvador')->format('d/m/Y') }}</div>
  </div>
</div>

<div class="recipe-name">{{ $receta['nombre'] }}</div>
<div class="recipe-breadcrumb">
  <span class="cat">{{ $receta['categoria'] ?? $receta['tipo'] ?? '' }}</span>
  &nbsp;·&nbsp;
  {{ $receta['tipo_receta'] === 'sub_receta' ? 'Sub-Receta' : 'Receta' }}
</div>

<hr class="header-rule">

{{-- RESUMEN ECONÓMICO --}}
@php
  $precioVenta = (float)($receta['precio'] ?? 0);
  $costoIngr   = $costo_total;
  $modificadores = $receta['modificadores'] ?? [];
  $costoMods = collect($modificadores)->sum(fn($g) => collect($g['opciones'] ?? [])->avg('costo_total') ?? 0);
  $costoTotal  = $costoIngr + $costoMods;
  $pctCosto    = $precioVenta > 0 ? ($costoTotal / $precioVenta * 100) : null;
  $margen      = $precioVenta > 0 ? (($precioVenta - $costoTotal) / $precioVenta * 100) : null;
@endphp

<div class="section">
  <div class="section-head">Resumen Económico</div>
  <div class="section-body">
    <div class="kpi-row">
      <div class="kpi-cell alt">
        <div class="kpi-lbl">Costo ingredientes</div>
        <div class="kpi-val">${{ number_format($costoIngr, 2) }}</div>
      </div>
      @if(count($modificadores) > 0)
      <div class="kpi-cell">
        <div class="kpi-lbl">Costo modificadores (prom.)</div>
        <div class="kpi-val">${{ number_format($costoMods, 2) }}</div>
      </div>
      <div class="kpi-cell alt">
        <div class="kpi-lbl">Costo total estimado</div>
        <div class="kpi-val">${{ number_format($costoTotal, 2) }}</div>
      </div>
      @endif
      <div class="kpi-cell">
        <div class="kpi-lbl">Precio de venta</div>
        @if($precioVenta > 0)
          <div class="kpi-val">${{ number_format($precioVenta, 2) }}</div>
        @else
          <div class="kpi-val muted">—</div>
        @endif
      </div>
      <div class="kpi-cell alt">
        <div class="kpi-lbl">% Costo / Margen</div>
        @if($pctCosto !== null)
          <div class="kpi-val gold">{{ number_format($pctCosto, 1) }}%</div>
          <div class="kpi-sub">Margen {{ number_format($margen, 1) }}%</div>
        @else
          <div class="kpi-val muted">—</div>
        @endif
      </div>
    </div>
  </div>
</div>

{{-- INFORMACIÓN GENERAL --}}
<div class="section">
  <div class="section-head">Información General</div>
  <div class="section-body padded">
    <table class="info-tbl">
      <tr>
        <td class="info-lbl">Tipo de receta</td>
        <td class="info-val">{{ $receta['tipo_receta'] === 'sub_receta' ? 'Sub-Receta' : 'Plato' }}</td>
      </tr>
      <tr>
        <td class="info-lbl">Categoría</td>
        <td class="info-val">{{ $receta['categoria'] ?? $receta['tipo'] ?? '—' }}</td>
      </tr>
      @if($receta['rendimiento'])
      <tr>
        <td class="info-lbl">Rendimiento</td>
        <td class="info-val">{{ number_format($receta['rendimiento'], 2) }} {{ $receta['rendimiento_unidad'] ?? '' }}</td>
      </tr>
      @endif
      @if(($receta['platos_semana'] ?? 0) > 0)
      <tr>
        <td class="info-lbl">Platos / semana</td>
        <td class="info-val">{{ $receta['platos_semana'] }}</td>
      </tr>
      @endif
      @if($receta['descripcion'])
      <tr>
        <td class="info-lbl">Descripción</td>
        <td class="info-val" style="font-weight:normal;color:#444;">{{ $receta['descripcion'] }}</td>
      </tr>
      @endif
    </table>
  </div>
</div>

{{-- INGREDIENTES --}}
<div class="section">
  <div class="section-head">Ingredientes</div>
  <table class="dt">
    <thead>
      <tr>
        <th width="4%">#</th>
        <th>Producto / Sub-Receta</th>
        <th class="r" width="13%">Cantidad</th>
        <th width="10%">Unidad</th>
        <th class="r" width="13%">Costo unit.</th>
        <th class="r" width="12%">Subtotal</th>
      </tr>
    </thead>
    <tbody>
      @foreach($receta['ingredientes'] as $i => $ing)
      @php
        $pu = (float)($ing['precio_unitario'] ?? 0);
        $qt = (float)($ing['cantidad_por_plato'] ?? 0);
        $st = $pu * $qt;
      @endphp
      <tr>
        <td class="sm">{{ $i + 1 }}</td>
        <td>
          {{ $ing['producto_nombre'] ?? '—' }}
          @if($ing['es_sub_receta'])<span class="tag-sub">SUB</span>@endif
        </td>
        <td class="r">{{ number_format($qt, 4) }}</td>
        <td class="sm">{{ $ing['unidad'] }}</td>
        <td class="r {{ $pu > 0 ? '' : 'zero' }}">${{ number_format($pu, 4) }}</td>
        <td class="r {{ $st > 0 ? '' : 'zero' }}">${{ number_format($st, 2) }}</td>
      </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <td colspan="4">Total &nbsp;·&nbsp; {{ count($receta['ingredientes']) }} línea(s)</td>
        <td></td>
        <td class="r">${{ number_format($costo_total, 2) }}</td>
      </tr>
    </tfoot>
  </table>
</div>

{{-- MODIFICADORES --}}
@if(count($modificadores) > 0)
<div class="section">
  <div class="section-head">
    Modificadores del Menú
    <span class="gold">&nbsp;·&nbsp; {{ count($modificadores) }} grupo{{ count($modificadores) > 1 ? 's' : '' }}</span>
  </div>
  <table class="dt">
    <thead>
      <tr>
        <th width="28%">Grupo</th>
        <th>Opción</th>
        <th class="r" width="13%">Cantidad</th>
        <th width="10%">Unidad</th>
        <th class="r" width="13%">Costo</th>
      </tr>
    </thead>
    <tbody>
      @foreach($modificadores as $gi => $grupo)
        @foreach($grupo['opciones'] as $j => $op)
        @php $co = (float)($op['costo_total'] ?? 0); @endphp
        <tr>
          <td style="font-size:9.5px;">
            @if($j === 0)<span class="grp-num">{{ $gi + 1 }}</span>{{ $grupo['grupo_nombre'] }}@endif
          </td>
          <td>{{ $op['nombre'] }}</td>
          <td class="r">{{ number_format((float)($op['cantidad'] ?? 0), 4) }}</td>
          <td class="sm">{{ $op['unidad'] ?? '' }}</td>
          <td class="r {{ $co > 0 ? '' : 'zero' }}">${{ number_format($co, 4) }}</td>
        </tr>
        @endforeach
      @endforeach
    </tbody>
  </table>
</div>
@endif

{{-- INSTRUCCIONES --}}
@if($receta['instrucciones'])
<div class="section">
  <div class="section-head">Instrucciones de Preparación</div>
  <div class="section-body padded">
    <div class="instrucciones">{{ $receta['instrucciones'] }}</div>
  </div>
</div>
@endif

{{-- FOTOGRAFÍAS --}}
@if($foto_plato || $foto_plateria)
<div class="section">
  <div class="section-head">Fotografías de Referencia</div>
  <div class="section-body padded">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        @if($foto_plato)
        <td style="padding-right:{{ $foto_plateria ? '10px' : '0' }};vertical-align:top;width:{{ $foto_plateria ? '50%' : '65%' }};">
          <div class="foto-lbl">Presentación del plato</div>
          <img src="{{ $foto_plato }}" class="foto-img" alt="Foto plato" />
        </td>
        @endif
        @if($foto_plateria)
        <td style="padding-left:{{ $foto_plato ? '10px' : '0' }};vertical-align:top;width:{{ $foto_plato ? '50%' : '65%' }};">
          <div class="foto-lbl">Loza / Montaje</div>
          <img src="{{ $foto_plateria }}" class="foto-img" alt="Foto loza" />
        </td>
        @endif
      </tr>
    </table>
  </div>
</div>
@endif

{{-- FOOTER --}}
<div class="footer">
  <div class="footer-l">
    <span class="footer-brand">Cadejo Brewing Company</span>
    &nbsp;·&nbsp; Documento interno — uso exclusivo del equipo de operaciones
  </div>
  <div class="footer-r">Generado el {{ \Carbon\Carbon::now('America/El_Salvador')->format('d/m/Y \a\l\a\s H:i') }}</div>
</div>

</div>{{-- /wrap --}}
</body>
</html>
