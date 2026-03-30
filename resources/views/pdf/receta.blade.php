<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { background: #fff; width: 100%; }
body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 10px;
  color: #1e1e1e;
  line-height: 1.45;
}

.wrap { padding: 16mm 17mm 14mm 17mm; }

/* ── ACCENT BAR ── */
.accent-bar { width: 100%; height: 4px; background: #1a1a1a; margin-bottom: 12px; position: relative; }
.accent-gold { position: absolute; top: 0; left: 0; height: 4px; width: 55px; background: #c49a28; }

/* ── HEADER ── */
.header { display: table; width: 100%; margin-bottom: 8px; }
.header-logo-wrap { display: table-cell; vertical-align: middle; width: 62px; }
.logo-box {
  background: #1a1a1a; width: 54px; height: 54px;
  text-align: center; vertical-align: middle;
  display: table-cell; border-radius: 3px;
}
.logo-box img { width: 46px; height: auto; vertical-align: middle; }
.header-center { display: table-cell; vertical-align: middle; padding-left: 11px; }
.header-right  { display: table-cell; vertical-align: middle; text-align: right; white-space: nowrap; padding-left: 10px; }
.company-name  { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; color: #555; }
.doc-type      { font-size: 7px; text-transform: uppercase; letter-spacing: 1.5px; color: #bbb; margin-top: 2px; }
.header-fecha  { font-size: 8.5px; color: #888; }

.recipe-name {
  font-size: 21px; font-weight: bold; color: #1a1a1a;
  letter-spacing: -0.3px; margin-top: 10px; margin-bottom: 3px;
  line-height: 1.2; text-transform: uppercase;
}
.recipe-breadcrumb { font-size: 8.5px; color: #bbb; letter-spacing: 0.3px; margin-bottom: 12px; }
.recipe-breadcrumb .cat { color: #c49a28; font-weight: bold; }
.header-rule { border: none; border-top: 1.5px solid #1a1a1a; margin-bottom: 16px; }

/* ── SECCIONES ── */
.section { margin-bottom: 16px; }
.section-head {
  background: #1a1a1a; color: #fff;
  padding: 5px 10px; font-size: 7px; font-weight: bold;
  letter-spacing: 1.8px; text-transform: uppercase;
}
.section-head .gold { color: #c49a28; }
.section-body { border: 1px solid #e0e0e0; border-top: none; }
.section-body.padded { padding: 11px 12px; }

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
  padding: 6px 8px; text-align: left;
  font-size: 7px; font-weight: bold;
  text-transform: uppercase; letter-spacing: 0.8px; color: #ddd;
}
.dt thead th.r { text-align: right; }
.dt tbody tr { border-bottom: 1px solid #ececec; page-break-inside: avoid; }
.dt tbody tr:nth-child(even) { background: #f9f9f9; }
.dt tbody td { padding: 4px 8px; font-size: 9.5px; vertical-align: middle; color: #222; }
.dt tbody td.r { text-align: right; }
.dt tbody td.sm { color: #aaa; font-size: 8.5px; }
.dt tfoot tr { background: #1a1a1a; border-top: 2px solid #c49a28; }
.dt tfoot td { padding: 5px 8px; font-size: 9.5px; font-weight: bold; color: #eee; }
.dt tfoot td.r { text-align: right; color: #c49a28; }

/* tags */
.tag-sub {
  display: inline-block; background: #ebebeb; color: #666;
  border-radius: 2px; padding: 1px 4px; font-size: 6.5px;
  font-weight: bold; margin-left: 4px; vertical-align: middle;
}
.grp-num {
  display: inline-block; background: #c49a28; color: #fff;
  border-radius: 2px; padding: 1px 5px; font-size: 6.5px;
  font-weight: bold; margin-right: 4px; vertical-align: middle;
}

/* ── DOS COLUMNAS con tabla HTML real ── */
.two-col-tbl { width: 100%; border-collapse: collapse; }
.two-col-tbl > tbody > tr > td { vertical-align: top; }
.col-divider { width: 10px; }
.col-subtitle {
  font-size: 7px; font-weight: bold; text-transform: uppercase;
  letter-spacing: 1.5px; color: #888;
  padding: 5px 8px; background: #f5f5f5;
  border-bottom: 1px solid #e0e0e0; border-top: 1px solid #e0e0e0;
}

/* ── INSTRUCCIONES ── */
.instrucciones { font-size: 10.5px; line-height: 1.9; color: #333; white-space: pre-wrap; }

/* ── FOTOS ── */
.foto-lbl { font-size: 7px; text-transform: uppercase; letter-spacing: 1px; color: #aaa; margin-bottom: 5px; }
.foto-img { width: 100%; max-height: 195px; object-fit: cover; border: 1px solid #ddd; display: block; }

/* ── FOOTER ── */
.footer {
  margin-top: 22px; border-top: 1px solid #e8e8e8;
  padding-top: 6px; font-size: 7px; color: #bbb;
  display: table; width: 100%;
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
  <div class="header-logo-wrap">
    <table cellpadding="0" cellspacing="0"><tr>
      <td class="logo-box">
        <img src="{{ public_path('images/cadejo_logo.png') }}" alt="Cadejo" />
      </td>
    </tr></table>
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

{{-- INGREDIENTES + MODIFICADORES --}}
@php
  $modificadores = $receta['modificadores'] ?? [];
  $tieneModificadores = count($modificadores) > 0;
@endphp

<div class="section">
  <div class="section-head">
    Ingredientes
    @if($tieneModificadores)<span class="gold">&nbsp;·&nbsp; Modificadores</span>@endif
  </div>
  <div class="section-body">

  @if($tieneModificadores)
  {{-- DOS COLUMNAS: tabla HTML real para que dompdf pagine bien --}}
  <table class="two-col-tbl" cellpadding="0" cellspacing="0">
    <tbody><tr>

      {{-- Columna izquierda: Ingredientes --}}
      <td style="width:48%;">
        <div class="col-subtitle">Ingredientes</div>
        <table class="dt">
          <thead>
            <tr>
              <th width="6%">#</th>
              <th>Producto</th>
              <th class="r" width="18%">Cant.</th>
              <th width="14%">Un.</th>
            </tr>
          </thead>
          <tbody>
            @foreach($receta['ingredientes'] as $i => $ing)
            <tr>
              <td class="sm">{{ $i + 1 }}</td>
              <td>
                {{ $ing['producto_nombre'] ?? '—' }}
                @if($ing['es_sub_receta'])<span class="tag-sub">SUB</span>@endif
              </td>
              <td class="r">{{ number_format((float)($ing['cantidad_por_plato'] ?? 0), 3) }}</td>
              <td class="sm">{{ $ing['unidad'] }}</td>
            </tr>
            @endforeach
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3">{{ count($receta['ingredientes']) }} línea(s)</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </td>

      {{-- Separador --}}
      <td class="col-divider"></td>

      {{-- Columna derecha: Modificadores --}}
      <td style="width:48%;">
        <div class="col-subtitle">Modificadores del Menú</div>
        <table class="dt">
          <thead>
            <tr>
              <th width="36%">Grupo</th>
              <th>Opción</th>
              <th class="r" width="16%">Cant.</th>
              <th width="12%">Un.</th>
            </tr>
          </thead>
          <tbody>
            @foreach($modificadores as $gi => $grupo)
              @foreach($grupo['opciones'] as $j => $op)
              <tr>
                <td style="font-size:9px;">
                  @if($j === 0)<span class="grp-num">{{ $gi + 1 }}</span>{{ $grupo['grupo_nombre'] }}@endif
                </td>
                <td>{{ $op['nombre'] }}</td>
                <td class="r">{{ number_format((float)($op['cantidad'] ?? 0), 3) }}</td>
                <td class="sm">{{ $op['unidad'] ?? 'u' }}</td>
              </tr>
              @endforeach
            @endforeach
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3">{{ count($modificadores) }} grupo(s)</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </td>

    </tr></tbody>
  </table>

  @else
  {{-- SIN MODIFICADORES: tabla full width --}}
  <table class="dt">
    <thead>
      <tr>
        <th width="5%">#</th>
        <th>Producto / Sub-Receta</th>
        <th class="r" width="15%">Cantidad</th>
        <th width="12%">Unidad</th>
      </tr>
    </thead>
    <tbody>
      @foreach($receta['ingredientes'] as $i => $ing)
      <tr>
        <td class="sm">{{ $i + 1 }}</td>
        <td>
          {{ $ing['producto_nombre'] ?? '—' }}
          @if($ing['es_sub_receta'])<span class="tag-sub">SUB</span>@endif
        </td>
        <td class="r">{{ number_format((float)($ing['cantidad_por_plato'] ?? 0), 3) }}</td>
        <td class="sm">{{ $ing['unidad'] }}</td>
      </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3">{{ count($receta['ingredientes']) }} línea(s)</td>
        <td></td>
      </tr>
    </tfoot>
  </table>
  @endif

  </div>
</div>

{{-- INSTRUCCIONES (solo si existen) --}}
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
