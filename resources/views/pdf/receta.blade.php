<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; background: #fff; }

  .header {
    background: #1c1917;
    color: #fff;
    padding: 16px 24px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
  }
  .header-title { font-size: 18px; font-weight: bold; letter-spacing: 0.5px; }
  .header-sub { font-size: 10px; color: #a8a29e; margin-top: 3px; }
  .header-meta { text-align: right; font-size: 10px; color: #a8a29e; }
  .header-meta .fecha { font-size: 11px; color: #e7e5e4; }

  .content { padding: 20px 24px; }

  /* Ficha principal */
  .ficha {
    border: 1px solid #e7e5e4;
    border-radius: 6px;
    margin-bottom: 18px;
    overflow: hidden;
  }
  .ficha-header {
    background: #f5f5f4;
    padding: 10px 14px;
    border-bottom: 1px solid #e7e5e4;
    font-weight: bold;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #78716c;
  }
  .ficha-body { padding: 14px; }

  .field-grid { display: table; width: 100%; }
  .field-row { display: table-row; }
  .field-label {
    display: table-cell;
    width: 30%;
    padding: 4px 8px 4px 0;
    color: #78716c;
    font-size: 10px;
    vertical-align: top;
  }
  .field-value {
    display: table-cell;
    padding: 4px 0;
    font-size: 11px;
    vertical-align: top;
  }

  .badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 9px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .badge-plato    { background: #d4e6d1; color: #166534; }
  .badge-subreceta { background: #dbeafe; color: #1e40af; }
  .badge-activa   { background: #d1fae5; color: #065f46; }

  /* Tabla ingredientes */
  .ingredientes-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 0;
  }
  .ingredientes-table thead tr {
    background: #1c1917;
    color: #fff;
  }
  .ingredientes-table thead th {
    padding: 7px 10px;
    text-align: left;
    font-size: 9.5px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.6px;
  }
  .ingredientes-table thead th.right { text-align: right; }
  .ingredientes-table tbody tr:nth-child(even) { background: #fafaf9; }
  .ingredientes-table tbody tr:nth-child(odd)  { background: #fff; }
  .ingredientes-table tbody td {
    padding: 6px 10px;
    border-bottom: 1px solid #f0efed;
    font-size: 10.5px;
    vertical-align: middle;
  }
  .ingredientes-table tbody td.right { text-align: right; font-variant-numeric: tabular-nums; }
  .ingredientes-table tfoot tr { background: #f5f5f4; }
  .ingredientes-table tfoot td {
    padding: 7px 10px;
    font-size: 10.5px;
    font-weight: bold;
    border-top: 2px solid #e7e5e4;
  }
  .ingredientes-table tfoot td.right { text-align: right; }

  .subreceta-tag {
    display: inline-block;
    background: #dbeafe;
    color: #1e40af;
    border-radius: 4px;
    padding: 1px 5px;
    font-size: 8.5px;
    font-weight: bold;
    margin-left: 4px;
    vertical-align: middle;
  }

  /* Instrucciones */
  .instrucciones {
    font-size: 11px;
    line-height: 1.6;
    color: #292524;
    white-space: pre-wrap;
  }

  /* Rendimiento */
  .rendimiento-box {
    display: inline-block;
    background: #fef3c7;
    border: 1px solid #fcd34d;
    border-radius: 6px;
    padding: 6px 14px;
    font-size: 11px;
    color: #92400e;
    font-weight: bold;
  }

  .footer {
    margin-top: 24px;
    border-top: 1px solid #e7e5e4;
    padding-top: 8px;
    font-size: 9px;
    color: #a8a29e;
    text-align: center;
  }
</style>
</head>
<body>

<div class="header">
  <div>
    <div class="header-title">{{ strtoupper($receta['nombre']) }}</div>
    <div class="header-sub">
      {{ $receta['categoria'] ?? $receta['tipo'] ?? '—' }}
      @if($receta['tipo_receta'] === 'sub_receta') &nbsp;·&nbsp; Sub-Receta @endif
    </div>
  </div>
  <div class="header-meta">
    <div class="fecha">{{ \Carbon\Carbon::now()->format('d/m/Y') }}</div>
    <div>Ficha de Receta</div>
  </div>
</div>

<div class="content">

  {{-- Ficha general --}}
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
        @if(!empty($receta['rendimiento']))
        <div class="field-row">
          <div class="field-label">Rendimiento</div>
          <div class="field-value">
            <span class="rendimiento-box">
              {{ number_format($receta['rendimiento'], 2) }} {{ $receta['rendimiento_unidad'] ?? '' }}
            </span>
          </div>
        </div>
        @endif
        @if(!empty($receta['platos_semana']))
        <div class="field-row">
          <div class="field-label">Platos / semana</div>
          <div class="field-value">{{ $receta['platos_semana'] }}</div>
        </div>
        @endif
        @if(!empty($receta['descripcion']))
        <div class="field-row">
          <div class="field-label">Descripción</div>
          <div class="field-value">{{ $receta['descripcion'] }}</div>
        </div>
        @endif
      </div>
    </div>
  </div>

  {{-- Ingredientes --}}
  <div class="ficha">
    <div class="ficha-header">Ingredientes</div>
    <table class="ingredientes-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Producto / Sub-Receta</th>
          <th class="right">Cantidad</th>
          <th>Unidad</th>
        </tr>
      </thead>
      <tbody>
        @foreach($receta['ingredientes'] as $i => $ing)
        <tr>
          <td style="color:#a8a29e;">{{ $i + 1 }}</td>
          <td>
            {{ $ing['producto_nombre'] ?? '—' }}
            @if($ing['es_sub_receta'])
              <span class="subreceta-tag">SUB</span>
            @endif
          </td>
          <td class="right">{{ number_format((float)$ing['cantidad_por_plato'], 4) }}</td>
          <td>{{ $ing['unidad'] }}</td>
        </tr>
        @endforeach
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2">Total de ingredientes</td>
          <td colspan="2" class="right">{{ count($receta['ingredientes']) }} líneas</td>
        </tr>
      </tfoot>
    </table>
  </div>

  {{-- Instrucciones --}}
  @if(!empty($receta['instrucciones']))
  <div class="ficha">
    <div class="ficha-header">Instrucciones de Preparación</div>
    <div class="ficha-body">
      <div class="instrucciones">{{ $receta['instrucciones'] }}</div>
    </div>
  </div>
  @endif

</div>

<div class="footer">
  Generado por Sistema de Recetas Cadejo &nbsp;·&nbsp; {{ \Carbon\Carbon::now()->format('d/m/Y H:i') }}
</div>

</body>
</html>
