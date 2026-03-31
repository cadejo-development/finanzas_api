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
  color: #1a1a1a;
  line-height: 1.5;
}

.wrap { padding: 11mm 14mm 10mm 14mm; }

/* ── OUTER DOCUMENT TABLE ─────────────────────────────────────────── */
.doc {
  width: 100%;
  border-collapse: collapse;
  border: 1.5px solid #444;
}
.doc > tbody > tr > td { padding: 0; border: none; }

/* ── INNER HEADER TABLE ───────────────────────────────────────────── */
.hdr { width: 100%; border-collapse: collapse; }
.hdr td { border: none; }

.logo-cell {
  width: 74px;
  border-right: 1.5px solid #444;
  text-align: center;
  vertical-align: middle;
  padding: 7px 8px;
}
.logo-cell img { width: 54px; height: auto; }

.company-cell {
  text-align: center;
  vertical-align: middle;
  padding: 8px 10px;
  font-size: 13px;
  font-weight: bold;
  text-transform: uppercase;
  letter-spacing: 1.8px;
}

.empty-top-cell {
  width: 108px;
  border-left: 1.5px solid #444;
  padding: 8px;
}

.location-cell {
  border-top: 1.5px solid #444;
  padding: 4px 10px;
  font-size: 9.5px;
  vertical-align: middle;
}

.fecha-cell {
  width: 108px;
  border-top: 1.5px solid #444;
  border-left: 1.5px solid #444;
  padding: 4px 10px;
  font-size: 9.5px;
  text-align: right;
  white-space: nowrap;
  vertical-align: middle;
}

/* ── TITULO / AREA ────────────────────────────────────────────────── */
.titulo-row td { border-top: 1.5px solid #444; padding: 8px 12px; font-size: 12px; font-weight: bold; }
.area-row   td { border-top: 1.5px solid #444; padding: 7px 12px; font-size: 11px; font-weight: bold; }

/* ── FOTO ─────────────────────────────────────────────────────────── */
.foto-row td { border-top: 1.5px solid #444; padding: 12px; text-align: center; }
.foto-plato  { max-width: 210px; max-height: 215px; object-fit: contain; }

/* ── INGREDIENTS + PROCEDURE TABLE ───────────────────────────────── */
.ing-row td { border-top: 1.5px solid #444; padding: 0; }

.ing { width: 100%; border-collapse: collapse; }

.ing thead th {
  padding: 5px 9px;
  font-size: 9.5px;
  font-weight: bold;
  text-align: left;
  border-right: 1.5px solid #444;
  border-bottom: 1.5px solid #444;
  vertical-align: middle;
  background: #fff;
}
.ing thead th:last-child { border-right: none; }

.ing tbody td {
  padding: 4px 9px;
  font-size: 9.5px;
  border-right: 1px solid #ccc;
  border-bottom: 1px solid #e8e8e8;
  vertical-align: top;
}
.ing tbody td.num { text-align: right; white-space: nowrap; }
.ing tbody td.sub-ing { text-decoration: underline; }

.ing tbody td.proc {
  border-left: 1.5px solid #444;
  border-right: none;
  border-bottom: none;
  padding: 8px 10px;
  font-size: 9.5px;
  line-height: 1.8;
  vertical-align: top;
}

/* ── FOOTER BULLETS ──────────────────────────────────────────────── */
.footer { margin-top: 10px; font-size: 10px; line-height: 2; }
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
            {{-- Logo: spans 2 header rows --}}
            <td class="logo-cell" rowspan="2">
              <img src="{{ public_path('images/cadejo_logo.png') }}" alt="Cadejo" />
            </td>
            {{-- Company name top --}}
            <td class="company-cell">Cadejo Brewing Company</td>
            {{-- Top-right: empty --}}
            <td class="empty-top-cell"></td>
          </tr>
          <tr>
            {{-- Sub-header: location | date --}}
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

  {{-- ── FOTO (solo si fue enviada) ─────────────────────────────── --}}
  @if($foto_plato)
  <tr class="foto-row">
    <td>
      <img src="{{ $foto_plato }}" class="foto-plato" alt="Foto del plato" />
    </td>
  </tr>
  @endif

  {{-- ── INGREDIENTES + PROCEDIMIENTO ───────────────────────────── --}}
  <tr class="ing-row">
    <td>
      <table class="ing" cellpadding="0" cellspacing="0">
        <thead>
          <tr>
            <th style="width:36%;">Ingredientes:</th>
            <th style="width:15%;">Cantidad:</th>
            <th style="width:11%;">Unidad:</th>
            <th>Procedimiento:</th>
          </tr>
        </thead>
        <tbody>
          @php
            $ingredientes = $receta['ingredientes'] ?? [];
            $n = count($ingredientes);

            // Formatear instrucciones: bold "Nota:" al inicio de línea
            $instruccionesHtml = '';
            foreach (explode("\n", trim($receta['instrucciones'] ?? '')) as $linea) {
                if (preg_match('/^(Nota:)(.*)/i', $linea, $m)) {
                    $instruccionesHtml .= '<strong>' . e($m[1]) . '</strong>' . e($m[2]) . '<br/>';
                } else {
                    $instruccionesHtml .= e($linea) . '<br/>';
                }
            }
          @endphp

          @if($n > 0)
            @foreach($ingredientes as $idx => $ing)
            <tr>
              <td class="{{ $ing['es_sub_receta'] ? 'sub-ing' : '' }}">
                {{ $ing['producto_nombre'] ?? '—' }}
              </td>
              <td class="num">{{ number_format((float)($ing['cantidad_por_plato'] ?? 0), 3) }}</td>
              <td>{{ $ing['unidad'] ?? '' }}</td>
              @if($idx === 0)
              {{-- Procedimiento: rowspan cubre todos los ingredientes --}}
              <td class="proc" rowspan="{{ $n }}">
                {!! $instruccionesHtml !!}
              </td>
              @endif
            </tr>
            @endforeach
          @else
            {{-- Sin ingredientes --}}
            <tr>
              <td colspan="3" style="padding:10px; color:#999; font-style:italic;">
                Sin ingredientes registrados.
              </td>
              <td class="proc">
                {!! $instruccionesHtml !!}
              </td>
            </tr>
          @endif
        </tbody>
      </table>
    </td>
  </tr>

</tbody>
</table>

{{-- ── FOOTER BULLETS ──────────────────────────────────────────────── --}}
<div class="footer">
  @if(!empty($receta['rendimiento']))
  <div>&#8226;&nbsp; Rendimiento: {{ number_format((float)$receta['rendimiento'], 3) }} {{ $receta['rendimiento_unidad'] ?? '' }}</div>
  @endif
  @if(!empty($receta['platos_semana']) && $receta['platos_semana'] > 0)
  <div>&#8226;&nbsp; Platos / semana: {{ $receta['platos_semana'] }}</div>
  @endif
</div>

</div>
</body>
</html>
