<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; background: #fff; }

  /* ── HEADER ── */
  .header {
    background: #1c1917;
    color: #fff;
    padding: 0;
    display: table;
    width: 100%;
  }
  .header-logo-cell {
    display: table-cell;
    width: 72px;
    padding: 12px 0 12px 18px;
    vertical-align: middle;
  }
  .header-logo-cell img {
    width: 56px;
    height: 56px;
    object-fit: contain;
  }
  .header-brand-cell {
    display: table-cell;
    padding: 12px 10px 12px 10px;
    vertical-align: middle;
    border-right: 1px solid #44403c;
  }
  .header-brand-name {
    font-size: 11px;
    font-weight: bold;
    color: #fbbf24;
    letter-spacing: 0.3px;
  }
  .header-brand-sub {
    font-size: 9px;
    color: #78716c;
    margin-top: 2px;
  }
  .header-title-cell {
    display: table-cell;
    padding: 12px 18px;
    vertical-align: middle;
  }
  .header-nombre {
    font-size: 16px;
    font-weight: bold;
    color: #fef3c7;
    letter-spacing: 0.3px;
  }
  .header-categoria {
    font-size: 10px;
    color: #a8a29e;
    margin-top: 3px;
  }
  .header-meta-cell {
    display: table-cell;
    padding: 12px 18px 12px 10px;
    vertical-align: middle;
    text-align: right;
    white-space: nowrap;
  }
  .header-fecha {
    font-size: 11px;
    color: #e7e5e4;
    font-weight: bold;
  }
  .header-doc {
    font-size: 9px;
    color: #78716c;
    margin-top: 2px;
  }

  /* ── CONTENT ── */
  .content { padding: 16px 22px; }

  /* ── FICHA ── */
  .ficha {
    border: 1px solid #e7e5e4;
    border-radius: 5px;
    margin-bottom: 14px;
    overflow: hidden;
  }
  .ficha-header {
    background: #f5f5f4;
    padding: 7px 12px;
    border-bottom: 1px solid #e7e5e4;
    font-weight: bold;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #78716c;
  }
  .ficha-body { padding: 12px; }

  .field-grid { display: table; width: 100%; }
  .field-row  { display: table-row; }
  .field-label {
    display: table-cell;
    width: 28%;
    padding: 3px 8px 3px 0;
    color: #78716c;
    font-size: 10px;
    vertical-align: top;
  }
  .field-value {
    display: table-cell;
    padding: 3px 0;
    font-size: 11px;
    vertical-align: top;
  }

  .badge {
    display: inline-block;
    padding: 1px 7px;
    border-radius: 8px;
    font-size: 8.5px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.4px;
  }
  .badge-plato     { background: #dcfce7; color: #166534; }
  .badge-subreceta { background: #dbeafe; color: #1e40af; }
  .badge-activa    { background: #d1fae5; color: #065f46; }

  /* ── PRECIOS BOX ── */
  .precio-box {
    display: table;
    width: 100%;
    margin-bottom: 14px;
    border-radius: 5px;
    overflow: hidden;
    border: 1px solid #d1fae5;
  }
  .precio-cell {
    display: table-cell;
    text-align: center;
    padding: 12px 8px;
    border-right: 1px solid #d1fae5;
    width: 33%;
  }
  .precio-cell:last-child { border-right: none; }
  .precio-cell-label {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #6b7280;
    margin-bottom: 4px;
  }
  .precio-cell-value {
    font-size: 16px;
    font-weight: bold;
  }
  .color-costo  { color: #059669; background: #ecfdf5; }
  .color-precio { color: #d97706; background: #fffbeb; }
  .color-margen { color: #7c3aed; background: #f5f3ff; }

  /* ── TABLA INGREDIENTES ── */
  .ingredientes-table {
    width: 100%;
    border-collapse: collapse;
  }
  .ingredientes-table thead tr { background: #1c1917; color: #fff; }
  .ingredientes-table thead th {
    padding: 6px 9px;
    text-align: left;
    font-size: 9px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .ingredientes-table thead th.right { text-align: right; }
  .ingredientes-table tbody tr:nth-child(even) { background: #fafaf9; }
  .ingredientes-table tbody tr:nth-child(odd)  { background: #fff; }
  .ingredientes-table tbody td {
    padding: 5px 9px;
    border-bottom: 1px solid #f0efed;
    font-size: 10px;
    vertical-align: middle;
  }
  .ingredientes-table tbody td.right { text-align: right; }
  .ingredientes-table tfoot tr { background: #f0fdf4; }
  .ingredientes-table tfoot td {
    padding: 7px 9px;
    font-size: 10.5px;
    font-weight: bold;
    border-top: 2px solid #6ee7b7;
  }
  .ingredientes-table tfoot td.right { text-align: right; color: #059669; }

  .sub-tag {
    display: inline-block;
    background: #dbeafe;
    color: #1e40af;
    border-radius: 3px;
    padding: 0 4px;
    font-size: 8px;
    font-weight: bold;
    margin-left: 3px;
    vertical-align: middle;
  }
  .costo-zero { color: #d1d5db; }
  .costo-ok   { color: #059669; font-weight: bold; }

  /* ── INSTRUCCIONES ── */
  .instrucciones {
    font-size: 11px;
    line-height: 1.7;
    color: #292524;
    white-space: pre-wrap;
  }

  /* ── FOOTER ── */
  .footer {
    margin-top: 20px;
    border-top: 1px solid #e7e5e4;
    padding-top: 6px;
    font-size: 8.5px;
    color: #a8a29e;
    text-align: center;
  }
</style>
</head>
<body>

{{-- ── HEADER ── --}}
<div class="header">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr>
      {{-- Marca --}}
      <td style="padding: 14px 16px; vertical-align: middle; border-right: 1px solid #44403c; white-space: nowrap;">
        <div style="font-size:10.5px; font-weight:bold; color:#fbbf24;">Cadejo Brewing Company</div>
        <div style="font-size:8.5px; color:#78716c; margin-top:2px;">Gestión de Operación</div>
      </td>

      {{-- Nombre receta --}}
      <td style="padding: 14px 18px; vertical-align: middle;">
        <div style="font-size:15px; font-weight:bold; color:#fef3c7; letter-spacing:0.3px;">
          {{ strtoupper($receta['nombre']) }}
        </div>
        <div style="font-size:9.5px; color:#a8a29e; margin-top:3px;">
          {{ $receta['categoria'] ?? $receta['tipo'] ?? '' }}
          @if($receta['tipo_receta'] === 'sub_receta') &nbsp;·&nbsp; Sub-Receta @endif
        </div>
      </td>

      {{-- Fecha --}}
      <td style="padding: 14px 18px 14px 10px; vertical-align: middle; text-align:right; white-space:nowrap;">
        <div style="font-size:12px; color:#fef3c7; font-weight:bold;">{{ \Carbon\Carbon::now()->format('d/m/Y') }}</div>
        <div style="font-size:8.5px; color:#78716c; margin-top:2px;">Receta</div>
      </td>
    </tr>
  </table>
</div>

<div class="content">

  {{-- ── PRECIOS Y COSTO ── --}}
  @php
    $precioVenta = (float) ($receta['precio'] ?? 0);
    $costoIngr   = $costo_total;
    $margen      = $precioVenta > 0 ? (($precioVenta - $costoIngr) / $precioVenta * 100) : null;
  @endphp
  @if($costoIngr > 0 || $precioVenta > 0)
  <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:14px; border:1px solid #d1fae5; border-radius:5px; overflow:hidden;">
    <tr>
      <td width="33%" style="text-align:center; padding:12px 8px; border-right:1px solid #d1fae5; background:#ecfdf5;">
        <div style="font-size:8.5px; text-transform:uppercase; letter-spacing:0.5px; color:#6b7280; margin-bottom:4px;">Costo estimado</div>
        <div style="font-size:17px; font-weight:bold; color:#059669;">${{ number_format($costoIngr, 2) }}</div>
        <div style="font-size:8px; color:#9ca3af; margin-top:2px;">por plato</div>
      </td>
      <td width="33%" style="text-align:center; padding:12px 8px; border-right:1px solid #fde68a; background:#fffbeb;">
        <div style="font-size:8.5px; text-transform:uppercase; letter-spacing:0.5px; color:#6b7280; margin-bottom:4px;">Precio de venta</div>
        @if($precioVenta > 0)
          <div style="font-size:17px; font-weight:bold; color:#d97706;">${{ number_format($precioVenta, 2) }}</div>
        @else
          <div style="font-size:13px; color:#d1d5db;">—</div>
        @endif
      </td>
      <td width="33%" style="text-align:center; padding:12px 8px; background:#f5f3ff;">
        <div style="font-size:8.5px; text-transform:uppercase; letter-spacing:0.5px; color:#6b7280; margin-bottom:4px;">Costo %</div>
        @if($margen !== null && $precioVenta > 0)
          <div style="font-size:17px; font-weight:bold; color:#7c3aed;">{{ number_format((($costoIngr / $precioVenta) * 100), 1) }}%</div>
          <div style="font-size:8px; color:#9ca3af; margin-top:2px;">margen {{ number_format($margen, 1) }}%</div>
        @else
          <div style="font-size:13px; color:#d1d5db;">—</div>
        @endif
      </td>
    </tr>
  </table>
  @endif

  {{-- ── INFORMACIÓN GENERAL ── --}}
  <div class="ficha">
    <div class="ficha-header">Información General</div>
    <div class="ficha-body">
      <div class="field-grid">
        <div class="field-row">
          <div class="field-label">Tipo</div>
          <div class="field-value">
            @if($receta['tipo_receta'] === 'sub_receta')
              <span class="badge badge-subreceta">Sub-Receta</span>
            @else
              <span class="badge badge-plato">Plato</span>
            @endif
            &nbsp;<span class="badge badge-activa">Activa</span>
          </div>
        </div>
        <div class="field-row">
          <div class="field-label">Categoría</div>
          <div class="field-value">{{ $receta['categoria'] ?? $receta['tipo'] ?? '—' }}</div>
        </div>
        @if($receta['rendimiento'])
        <div class="field-row">
          <div class="field-label">Rendimiento</div>
          <div class="field-value">
            <span style="background:#fef3c7; border:1px solid #fcd34d; border-radius:4px; padding:1px 8px; color:#92400e; font-weight:bold;">
              {{ number_format($receta['rendimiento'], 2) }} {{ $receta['rendimiento_unidad'] ?? '' }}
            </span>
          </div>
        </div>
        @endif
        @if(($receta['platos_semana'] ?? 0) > 0)
        <div class="field-row">
          <div class="field-label">Platos / semana</div>
          <div class="field-value">{{ $receta['platos_semana'] }}</div>
        </div>
        @endif
        @if($receta['descripcion'])
        <div class="field-row">
          <div class="field-label">Descripción</div>
          <div class="field-value">{{ $receta['descripcion'] }}</div>
        </div>
        @endif
      </div>
    </div>
  </div>

  {{-- ── INGREDIENTES ── --}}
  <div class="ficha">
    <div class="ficha-header">Ingredientes</div>
    <table class="ingredientes-table">
      <thead>
        <tr>
          <th width="4%">#</th>
          <th>Producto / Sub-Receta</th>
          <th class="right" width="12%">Cantidad</th>
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
          <td style="color:#a8a29e; font-size:9px;">{{ $i + 1 }}</td>
          <td>
            {{ $ing['producto_nombre'] ?? '—' }}
            @if($ing['es_sub_receta'])<span class="sub-tag">SUB</span>@endif
          </td>
          <td class="right">{{ number_format($cant, 4) }}</td>
          <td style="color:#6b7280;">{{ $ing['unidad'] }}</td>
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
          <td colspan="4">Total · {{ count($receta['ingredientes']) }} líneas</td>
          <td class="right" colspan="2">${{ number_format($costo_total, 2) }}</td>
        </tr>
      </tfoot>
    </table>
  </div>

  {{-- ── INSTRUCCIONES ── --}}
  @if($receta['instrucciones'])
  <div class="ficha">
    <div class="ficha-header">Instrucciones de Preparación</div>
    <div class="ficha-body">
      <div class="instrucciones">{{ $receta['instrucciones'] }}</div>
    </div>
  </div>
  @endif

</div>

<div class="footer">
  Cadejo Brewing Company — Recetas &nbsp;·&nbsp; {{ \Carbon\Carbon::now()->format('d/m/Y H:i') }}
</div>

</body>
</html>
