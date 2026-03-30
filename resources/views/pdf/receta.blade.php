<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1a1a1a; background: #fff; }

  /* ── HEADER ── */
  .header-wrap {
    border-bottom: 3px solid #1a1a1a;
    padding-bottom: 10px;
    margin-bottom: 18px;
  }
  .header-top {
    display: table;
    width: 100%;
  }
  .header-brand {
    display: table-cell;
    vertical-align: bottom;
  }
  .header-brand-name {
    font-size: 13px;
    font-weight: bold;
    color: #1a1a1a;
    letter-spacing: 0.5px;
    text-transform: uppercase;
  }
  .header-doc-type {
    font-size: 8.5px;
    color: #666;
    margin-top: 2px;
    letter-spacing: 1px;
    text-transform: uppercase;
  }
  .header-meta {
    display: table-cell;
    vertical-align: bottom;
    text-align: right;
    white-space: nowrap;
  }
  .header-fecha {
    font-size: 10px;
    color: #333;
  }
  .header-divider {
    border: none;
    border-top: 1px solid #ccc;
    margin: 8px 0 6px;
  }
  .header-nombre {
    font-size: 18px;
    font-weight: bold;
    color: #1a1a1a;
    letter-spacing: 0.3px;
    text-transform: uppercase;
  }
  .header-sub {
    font-size: 9.5px;
    color: #555;
    margin-top: 3px;
  }

  /* ── CONTENT ── */
  .content { padding: 0 0 16px; }

  /* ── SECCIONES ── */
  .section { margin-bottom: 16px; }
  .section-title {
    font-size: 8px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: #444;
    border-bottom: 1px solid #ccc;
    padding-bottom: 4px;
    margin-bottom: 10px;
  }

  /* ── RESUMEN COSTOS ── */
  .costos-table { width: 100%; border-collapse: collapse; }
  .costos-table td {
    padding: 8px 12px;
    border: 1px solid #ddd;
    font-size: 10px;
    vertical-align: middle;
  }
  .costos-table .label {
    font-size: 8.5px;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #666;
    display: block;
    margin-bottom: 2px;
  }
  .costos-table .valor {
    font-size: 15px;
    font-weight: bold;
    color: #1a1a1a;
  }
  .costos-table .valor-sm {
    font-size: 11px;
    font-weight: bold;
    color: #555;
  }
  .costos-highlight { background: #f7f7f7; }

  /* ── INFO GENERAL ── */
  .info-table { width: 100%; border-collapse: collapse; }
  .info-table tr { border-bottom: 1px solid #eee; }
  .info-table tr:last-child { border-bottom: none; }
  .info-table td { padding: 5px 0; font-size: 10px; vertical-align: top; }
  .info-label { color: #666; width: 28%; font-size: 9.5px; }
  .info-value { color: #1a1a1a; }

  /* ── TABLAS DATOS ── */
  .data-table { width: 100%; border-collapse: collapse; }
  .data-table thead tr { background: #1a1a1a; color: #fff; }
  .data-table thead th {
    padding: 6px 10px;
    text-align: left;
    font-size: 8.5px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.7px;
  }
  .data-table thead th.right { text-align: right; }
  .data-table tbody tr { border-bottom: 1px solid #e8e8e8; }
  .data-table tbody tr:nth-child(even) { background: #fafafa; }
  .data-table tbody td {
    padding: 5px 10px;
    font-size: 10px;
    vertical-align: middle;
    color: #1a1a1a;
  }
  .data-table tbody td.right { text-align: right; }
  .data-table tfoot tr { background: #f0f0f0; border-top: 2px solid #1a1a1a; }
  .data-table tfoot td {
    padding: 7px 10px;
    font-size: 10.5px;
    font-weight: bold;
  }
  .data-table tfoot td.right { text-align: right; }

  .sub-tag {
    display: inline-block;
    background: #e8e8e8;
    color: #444;
    border-radius: 2px;
    padding: 0 4px;
    font-size: 7.5px;
    font-weight: bold;
    margin-left: 4px;
    vertical-align: middle;
    letter-spacing: 0.3px;
  }
  .mod-grupo-tag {
    display: inline-block;
    background: #1a1a1a;
    color: #fff;
    border-radius: 2px;
    padding: 0 5px;
    font-size: 7.5px;
    font-weight: bold;
    margin-right: 4px;
    vertical-align: middle;
  }
  .costo-zero { color: #bbb; }
  .costo-ok   { color: #1a1a1a; font-weight: bold; }

  /* ── INSTRUCCIONES ── */
  .instrucciones {
    font-size: 10.5px;
    line-height: 1.8;
    color: #222;
    white-space: pre-wrap;
  }

  /* ── FOTOS ── */
  .fotos-caption {
    font-size: 8px;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #666;
    margin-bottom: 4px;
  }

  /* ── FOOTER ── */
  .footer {
    margin-top: 24px;
    border-top: 1px solid #ccc;
    padding-top: 7px;
    font-size: 8px;
    color: #999;
    display: table;
    width: 100%;
  }
  .footer-left  { display: table-cell; }
  .footer-right { display: table-cell; text-align: right; }
</style>
</head>
<body>

{{-- ── HEADER ── --}}
<div class="header-wrap">
  <div class="header-top">
    <div class="header-brand">
      <div class="header-brand-name">Cadejo Brewing Company</div>
      <div class="header-doc-type">Ficha Técnica de Receta</div>
    </div>
    <div class="header-meta">
      <div class="header-fecha">{{ \Carbon\Carbon::now('America/El_Salvador')->format('d/m/Y') }}</div>
    </div>
  </div>
  <hr class="header-divider">
  <div class="header-nombre">{{ $receta['nombre'] }}</div>
  <div class="header-sub">
    {{ $receta['categoria'] ?? $receta['tipo'] ?? '' }}
    @if($receta['tipo_receta'] === 'sub_receta') &nbsp;·&nbsp; Sub-Receta @else &nbsp;·&nbsp; Receta @endif
  </div>
</div>

<div class="content">

  {{-- ── RESUMEN DE COSTOS ── --}}
  @php
    $precioVenta   = (float) ($receta['precio'] ?? 0);
    $costoIngr     = $costo_total;
    $modificadores = $receta['modificadores'] ?? [];
    $costoMods = collect($modificadores)->sum(function($g) {
        $opciones = collect($g['opciones'] ?? []);
        return $opciones->isNotEmpty() ? $opciones->avg('costo_total') : 0;
    });
    $costoTotal = $costoIngr + $costoMods;
    $pctCosto   = ($precioVenta > 0) ? (($costoTotal / $precioVenta) * 100) : null;
    $margen     = ($precioVenta > 0) ? (($precioVenta - $costoTotal) / $precioVenta * 100) : null;
  @endphp

  <div class="section">
    <div class="section-title">Resumen Económico</div>
    <table class="costos-table">
      <tr>
        <td class="costos-highlight">
          <span class="label">Costo ingredientes</span>
          <span class="valor">${{ number_format($costoIngr, 2) }}</span>
        </td>
        @if(count($modificadores) > 0)
        <td>
          <span class="label">Costo modificadores (prom.)</span>
          <span class="valor">${{ number_format($costoMods, 2) }}</span>
        </td>
        <td class="costos-highlight">
          <span class="label">Costo total estimado</span>
          <span class="valor">${{ number_format($costoTotal, 2) }}</span>
        </td>
        @endif
        <td>
          <span class="label">Precio de venta</span>
          @if($precioVenta > 0)
            <span class="valor">${{ number_format($precioVenta, 2) }}</span>
          @else
            <span class="valor-sm" style="color:#bbb;">No definido</span>
          @endif
        </td>
        <td class="costos-highlight">
          <span class="label">% Costo / Margen</span>
          @if($pctCosto !== null)
            <span class="valor">{{ number_format($pctCosto, 1) }}%</span>
            <span style="font-size:8px; color:#666; display:block; margin-top:1px;">Margen {{ number_format($margen, 1) }}%</span>
          @else
            <span class="valor-sm" style="color:#bbb;">—</span>
          @endif
        </td>
      </tr>
    </table>
  </div>

  {{-- ── INFORMACIÓN GENERAL ── --}}
  <div class="section">
    <div class="section-title">Información General</div>
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
        <td class="info-label">Rendimiento por batch</td>
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
        <td class="info-value">{{ $receta['descripcion'] }}</td>
      </tr>
      @endif
    </table>
  </div>

  {{-- ── INGREDIENTES ── --}}
  <div class="section">
    <div class="section-title">Ingredientes</div>
    <table class="data-table">
      <thead>
        <tr>
          <th width="4%">#</th>
          <th>Producto / Sub-Receta</th>
          <th class="right" width="13%">Cantidad</th>
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
          <td style="color:#999; font-size:9px;">{{ $i + 1 }}</td>
          <td>
            {{ $ing['producto_nombre'] ?? '—' }}
            @if($ing['es_sub_receta'])<span class="sub-tag">SUB</span>@endif
          </td>
          <td class="right">{{ number_format($cant, 4) }}</td>
          <td style="color:#555;">{{ $ing['unidad'] }}</td>
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
          <td colspan="4">Total · {{ count($receta['ingredientes']) }} línea(s)</td>
          <td></td>
          <td class="right">${{ number_format($costo_total, 2) }}</td>
        </tr>
      </tfoot>
    </table>
  </div>

  {{-- ── MODIFICADORES ── --}}
  @if(count($modificadores) > 0)
  <div class="section">
    <div class="section-title">Modificadores del Menú &nbsp;({{ count($modificadores) }} grupo{{ count($modificadores) > 1 ? 's' : '' }})</div>
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
        @foreach($modificadores as $grupo)
          @foreach($grupo['opciones'] as $j => $op)
          @php $costoOp = (float)($op['costo_total'] ?? 0); @endphp
          <tr>
            <td style="font-size:9.5px;">
              @if($j === 0)
                <span class="mod-grupo-tag">{{ $j + 1 }}</span>{{ $grupo['grupo_nombre'] }}
              @endif
            </td>
            <td>{{ $op['nombre'] }}</td>
            <td class="right">{{ number_format((float)($op['cantidad'] ?? 0), 4) }}</td>
            <td style="color:#555;">{{ $op['unidad'] ?? '' }}</td>
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
    <div class="instrucciones">{{ $receta['instrucciones'] }}</div>
  </div>
  @endif

  {{-- ── FOTOGRAFÍAS (al final) ── --}}
  @if($foto_plato || $foto_plateria)
  <div class="section">
    <div class="section-title">Fotografías de Referencia</div>
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        @if($foto_plato)
        <td style="padding-right:{{ $foto_plateria ? '8px' : '0' }}; vertical-align:top; width:{{ $foto_plateria ? '50%' : '60%' }};">
          <div class="fotos-caption">Presentación del plato</div>
          <img src="{{ $foto_plato }}" style="width:100%; max-height:190px; object-fit:cover; border:1px solid #ccc;" alt="Foto del plato" />
        </td>
        @endif
        @if($foto_plateria)
        <td style="padding-left:{{ $foto_plato ? '8px' : '0' }}; vertical-align:top; width:{{ $foto_plato ? '50%' : '60%' }};">
          <div class="fotos-caption">Loza / Montaje</div>
          <img src="{{ $foto_plateria }}" style="width:100%; max-height:190px; object-fit:cover; border:1px solid #ccc;" alt="Foto de la loza" />
        </td>
        @endif
      </tr>
    </table>
  </div>
  @endif

</div>

<div class="footer">
  <div class="footer-left">Cadejo Brewing Company &nbsp;·&nbsp; Documento interno — uso exclusivo del equipo de operaciones</div>
  <div class="footer-right">Generado el {{ \Carbon\Carbon::now('America/El_Salvador')->format('d/m/Y \a\l\a\s H:i') }}</div>
</div>

</body>
</html>
