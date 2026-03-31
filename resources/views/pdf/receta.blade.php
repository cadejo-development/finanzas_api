<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { background: #fff; width: 100%; height: 100%; }
body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 11px;
  color: #1a1a1a;
  line-height: 1.5;
}

.wrap { padding: 10mm 12mm 10mm 12mm; height: 100%; }

/* ── OUTER DOCUMENT TABLE ─────────────────────────────────────────── */
/* height forzado para que el marco cubra toda la página */
.doc {
  width: 100%;
  height: 252mm;
  border-collapse: collapse;
  border: 1.5px solid #444;
}
.doc > tbody > tr > td { padding: 0; border: none; }

/* ── INNER HEADER TABLE ───────────────────────────────────────────── */
.hdr { width: 100%; border-collapse: collapse; }
.hdr td { border: none; color: #1a1a1a; }

.logo-cell {
  width: 84px;
  border-right: 1.5px solid #444;
  text-align: center;
  vertical-align: middle;
  padding: 8px;
}
.logo-wrap {
  display: inline-block;
  background: #1c1c1c;
  border-radius: 50%;
  width: 56px;
  height: 56px;
  text-align: center;
  vertical-align: middle;
  padding-top: 6px;
}
.logo-wrap img { width: 44px; height: 44px; }

.company-cell {
  text-align: center;
  vertical-align: middle;
  padding: 10px 10px;
  font-size: 14px;
  font-weight: bold;
  text-transform: uppercase;
  letter-spacing: 2px;
  color: #1a1a1a;
}

/* Celda top-derecha: borde izquierdo visible */
.top-right-cell {
  width: 116px;
  border-left: 1.5px solid #444;
  padding: 8px;
}

.location-cell {
  border-top: 1.5px solid #444;
  padding: 4px 10px;
  font-size: 10px;
  vertical-align: middle;
  color: #1a1a1a;
}

.fecha-cell {
  width: 116px;
  border-top: 1.5px solid #444;
  border-left: 1.5px solid #444;
  padding: 4px 10px;
  font-size: 10px;
  text-align: right;
  white-space: nowrap;
  vertical-align: middle;
  color: #1a1a1a;
}

/* ── TITULO / AREA ────────────────────────────────────────────────── */
.titulo-row td {
  border-top: 1.5px solid #444;
  padding: 8px 14px;
  font-size: 12px;
  font-weight: bold;
  color: #1a1a1a;
}
.area-row td {
  border-top: 1.5px solid #444;
  padding: 7px 14px;
  font-size: 11px;
  font-weight: bold;
  color: #1a1a1a;
}

/* ── FOTO ─────────────────────────────────────────────────────────── */
.foto-row td {
  border-top: 1.5px solid #444;
  padding: 14px;
  text-align: center;
  vertical-align: middle;
  height: 190px;
}
.foto-table { border-collapse: collapse; margin: 0 auto; }
.foto-table td { border: none; padding: 0 10px; text-align: center; vertical-align: middle; }
.foto-plato    { max-width: 200px; max-height: 165px; object-fit: contain; }
.foto-plateria { max-width: 200px; max-height: 165px; object-fit: contain; }

/* Placeholder cuando no hay foto */
.foto-placeholder {
  display: inline-block;
  width: 190px;
  height: 155px;
  border: 1.5px dashed #ccc;
  background: #f5f5f5;
}

/* ── INGREDIENTS + PROCEDURE TABLE ───────────────────────────────── */
.ing-row td { border-top: 1.5px solid #444; padding: 0; }

.ing { width: 100%; border-collapse: collapse; }

.ing thead th {
  padding: 6px 10px;
  font-size: 10.5px;
  font-weight: bold;
  text-align: left;
  border-right: 1.5px solid #444;
  border-bottom: 1.5px solid #444;
  vertical-align: middle;
  background: #fff;
  color: #1a1a1a;
}
.ing thead th:last-child { border-right: none; }

.ing tbody td {
  padding: 5px 10px;
  font-size: 10.5px;
  border-right: 1px solid #ccc;
  border-bottom: 1px solid #e0e0e0;
  vertical-align: top;
  color: #1a1a1a;
}
.ing tbody td.num  { text-align: right; white-space: nowrap; }
.ing tbody td.sub-ing { text-decoration: underline; color: #1a1a1a; }

.ing tbody td.proc {
  border-left: 1.5px solid #444;
  border-right: none;
  border-bottom: none;
  padding: 10px 12px;
  font-size: 10.5px;
  line-height: 1.9;
  vertical-align: top;
  color: #1a1a1a;
}

/* ── FILA ESPACIADORA (empuja el footer hacia abajo) ─────────────── */
.spacer-row td { border-top: none; padding: 0; }

/* ── FOOTER (dentro del marco) ───────────────────────────────────── */
.footer-row td {
  border-top: 1.5px solid #444;
  padding: 10px 16px;
  font-size: 10.5px;
  color: #1a1a1a;
  line-height: 2.2;
  vertical-align: top;
}
</style>
</head>
<body>
<div class="wrap">

<table class="doc" cellpadding="0" cellspacing="0">
<tbody>

  {{-- ── HEADER ──────────────────────────────────────────────────── --}}
  <tr>
    <td>
      <table class="hdr" cellpadding="0" cellspacing="0">
        <tbody>
          <tr>
            <td class="logo-cell" rowspan="2">
              <div class="logo-wrap">
                <img src="{{ public_path('images/cadejo_logo.png') }}" alt="Cadejo" />
              </div>
            </td>
            <td class="company-cell">Cadejo Brewing Company</td>
            <td class="top-right-cell"></td>
          </tr>
          <tr>
            <td class="location-cell">{{ $sucursal_nombre ?? 'Cadejo Brewing Company' }}</td>
            <td class="fecha-cell">Fecha: {{ \Carbon\Carbon::now('America/El_Salvador')->format('d/m/Y') }}</td>
          </tr>
        </tbody>
      </table>
    </td>
  </tr>

  {{-- ── TITULO ───────────────────────────────────────────────────── --}}
  <tr class="titulo-row">
    <td>
      TITULO: {{ $receta['tipo_receta'] === 'sub_receta' ? 'SUB. ' : '' }}{{ strtoupper($receta['nombre']) }}
    </td>
  </tr>

  {{-- ── AREA ────────────────────────────────────────────────────── --}}
  <tr class="area-row">
    <td>
      AREA: {{ strtoupper($receta['categoria'] ?? $receta['tipo'] ?? '—') }}
    </td>
  </tr>

  {{-- ── FOTO: siempre visible; dos fotos lado a lado cuando existen ambas --}}
  <tr class="foto-row">
    <td>
      @if($foto_plato || $foto_plateria)
        <table class="foto-table" cellpadding="0" cellspacing="0">
          <tbody><tr>
            @if($foto_plato)
            <td><img src="{{ $foto_plato }}" class="foto-plato" alt="Foto plato" /></td>
            @endif
            @if($foto_plateria)
            <td><img src="{{ $foto_plateria }}" class="foto-plateria" alt="Loza" /></td>
            @endif
          </tr></tbody>
        </table>
      @else
        <div class="foto-placeholder"></div>
      @endif
    </td>
  </tr>

  {{-- ── INGREDIENTES + PROCEDIMIENTO ───────────────────────────── --}}
  @php
    $ingredientes = $receta['ingredientes'] ?? [];
    $n = count($ingredientes);

    $instruccionesRaw  = trim($receta['instrucciones'] ?? '');
    $instruccionesHtml = '';
    if ($instruccionesRaw !== '') {
        foreach (explode("\n", $instruccionesRaw) as $linea) {
            if (preg_match('/^(Nota:)(.*)/i', $linea, $m)) {
                $instruccionesHtml .= '<strong>' . e($m[1]) . '</strong>' . e($m[2]) . '<br/>';
            } else {
                $instruccionesHtml .= e($linea) . '<br/>';
            }
        }
    }
  @endphp
  <tr class="ing-row">
    <td>
      <table class="ing" cellpadding="0" cellspacing="0">
        <thead>
          <tr>
            <th style="width:36%;">Ingredientes:</th>
            <th style="width:14%;">Cantidad:</th>
            <th style="width:10%;">Unidad:</th>
            <th>Procedimiento:</th>
          </tr>
        </thead>
        <tbody>
          @if($n > 0)
            @foreach($ingredientes as $idx => $ing)
            <tr>
              <td class="{{ $ing['es_sub_receta'] ? 'sub-ing' : '' }}" style="color:#1a1a1a;">
                {{ $ing['producto_nombre'] ?? '—' }}
              </td>
              <td class="num" style="color:#1a1a1a;">{{ number_format((float)($ing['cantidad_por_plato'] ?? 0), 3) }}</td>
              <td style="color:#1a1a1a;">{{ $ing['unidad'] ?? '' }}</td>
              @if($idx === 0)
              <td class="proc" rowspan="{{ $n }}">
                @if($instruccionesHtml !== '')
                  {!! $instruccionesHtml !!}
                @else
                  <span style="color:#bbb; font-style:italic;">Sin instrucciones registradas.</span>
                @endif
              </td>
              @endif
            </tr>
            @endforeach
          @else
            <tr>
              <td colspan="3" style="padding:12px; color:#aaa; font-style:italic;">Sin ingredientes registrados.</td>
              <td class="proc">
                @if($instruccionesHtml !== '')
                  {!! $instruccionesHtml !!}
                @else
                  <span style="color:#bbb; font-style:italic;">Sin instrucciones registradas.</span>
                @endif
              </td>
            </tr>
          @endif
        </tbody>
      </table>
    </td>
  </tr>

  {{-- ── ESPACIADOR: empuja footer al fondo del marco ────────────── --}}
  <tr class="spacer-row" style="height:100%;"><td></td></tr>

  {{-- ── FOOTER dentro del marco bordeado ───────────────────────── --}}
  @php
    $hayFooter = !empty($receta['rendimiento'])
              || (!empty($receta['platos_semana']) && $receta['platos_semana'] > 0)
              || !empty($receta['vida_util'])
              || !empty($receta['tiempo_vida']);
  @endphp
  @if($hayFooter)
  <tr class="footer-row">
    <td>
      @if(!empty($receta['rendimiento']))
      <div>&#8226;&nbsp; Rendimiento: {{ number_format((float)$receta['rendimiento'], 3) }} {{ $receta['rendimiento_unidad'] ?? '' }}</div>
      @endif
      @if(!empty($receta['platos_semana']) && $receta['platos_semana'] > 0)
      <div>&#8226;&nbsp; Platos / semana: {{ $receta['platos_semana'] }}</div>
      @endif
      @if(!empty($receta['vida_util']))
      <div>&#8226;&nbsp; Tiempo de vida: {{ $receta['vida_util'] }}</div>
      @elseif(!empty($receta['tiempo_vida']))
      <div>&#8226;&nbsp; Tiempo de vida: {{ $receta['tiempo_vida'] }}</div>
      @endif
    </td>
  </tr>
  @endif

</tbody>
</table>

</div>
</body>
</html>
