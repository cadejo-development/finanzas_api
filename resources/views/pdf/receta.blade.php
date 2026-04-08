<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { background: #fff; width: 100%; }
body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 11px;
  color: #1a1a1a;
  line-height: 1.5;
}

/* ── BORDE DE PÁGINA FIJO ─────────────────────────────────────────── */
/* position:fixed en DomPDF dibuja en cada página sin ocupar espacio  */
.page-border {
  position: fixed;
  top: 8mm;
  left: 10mm;
  right: 10mm;
  bottom: 8mm;
  border: 1.5px solid #444;
}

.wrap { padding: 10mm 12mm 10mm 12mm; }

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
  padding: 10px;
  font-size: 14px;
  font-weight: bold;
  text-transform: uppercase;
  letter-spacing: 2px;
  color: #1a1a1a;
}

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
.titulo-row td { border-top: 1.5px solid #444; padding: 8px 14px; font-size: 12px; font-weight: bold; color: #1a1a1a; }
.area-row   td { border-top: 1.5px solid #444; padding: 7px 14px; font-size: 11px; font-weight: bold; color: #1a1a1a; }

/* ── FOTO ─────────────────────────────────────────────────────────── */
.foto-row td {
  border-top: 1.5px solid #444;
  padding: 14px;
  text-align: center;
  vertical-align: middle;
  height: 185px;
}
.foto-inner { width: 100%; text-align: center; }
.foto-plato    { max-width: 195px; max-height: 158px; object-fit: contain; }
.foto-plateria { max-width: 195px; max-height: 158px; object-fit: contain; }

.foto-placeholder {
  display: inline-block;
  width: 175px;
  height: 148px;
  border: 1.5px dashed #ccc;
  background: #f5f5f5;
  margin: 0 6px;
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
  border-right: 1.5px solid #444;
  border-bottom: 1.5px solid #444;
  vertical-align: top;
  color: #1a1a1a;
}
.ing tbody td.num { text-align: right; white-space: nowrap; color: #1a1a1a; }

.ing tbody td.proc {
  border-left: 1.5px solid #444;
  border-right: none;
  border-bottom: 1px solid #e8e8e8;
  padding: 4px 12px;
  font-size: 10.5px;
  line-height: 1.75;
  vertical-align: top;
  color: #1a1a1a;
}

/* ── FOOTER (dentro del contenido, antes del fin del marco) ──────── */
.footer-section {
  margin-top: 12px;
  padding: 6px 2px;
  font-size: 10.5px;
  color: #1a1a1a;
  line-height: 2.2;
  border-top: 1.5px solid #444;
}
</style>
</head>
<body>

{{-- Marco fijo que cubre toda la página (position:fixed se repite por página en DomPDF) --}}
<div class="page-border"></div>

<div class="wrap">

  {{-- ── HEADER ──────────────────────────────────────────────────── --}}
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
        <td class="location-cell">
          {{ $sucursal_nombre ?? 'Cadejo Brewing Company' }}
          @if(!empty($sucursales_nombres))
            &nbsp;—&nbsp;{{ $sucursales_nombres }}
          @endif
        </td>
        <td class="fecha-cell">Fecha: {{ \Carbon\Carbon::now('America/El_Salvador')->format('d/m/Y') }}</td>
      </tr>
    </tbody>
  </table>

  {{-- ── TITULO ───────────────────────────────────────────────────── --}}
  <table style="width:100%; border-collapse:collapse;">
    <tbody>
      <tr class="titulo-row">
        <td>TITULO: {{ $receta['tipo_receta'] === 'sub_receta' ? 'SUB. ' : '' }}{{ strtoupper($receta['nombre']) }}</td>
      </tr>
      <tr class="area-row">
        <td>AREA: {{ strtoupper($receta['categoria'] ?? $receta['tipo'] ?? '—') }}</td>
      </tr>
    </tbody>
  </table>

  {{-- ── FOTO ─────────────────────────────────────────────────────── --}}
  <table style="width:100%; border-collapse:collapse;">
    <tbody>
      <tr class="foto-row">
        <td>
          @if($foto_plato && $foto_plateria)
            {{-- Dos fotos lado a lado --}}
            <table style="border-collapse:collapse; margin:0 auto;" cellpadding="0" cellspacing="0">
              <tbody><tr>
                <td style="padding:0 8px; text-align:center; vertical-align:middle;">
                  <img src="{{ $foto_plato }}" class="foto-plato" alt="Foto plato" />
                </td>
                <td style="padding:0 8px; text-align:center; vertical-align:middle;">
                  <img src="{{ $foto_plateria }}" class="foto-plateria" alt="Loza" />
                </td>
              </tr></tbody>
            </table>
          @elseif($foto_plato)
            <img src="{{ $foto_plato }}" class="foto-plato" alt="Foto plato" />
          @elseif($foto_plateria)
            <img src="{{ $foto_plateria }}" class="foto-plateria" alt="Loza" />
          @else
            {{-- Dos placeholders cuando no hay ninguna foto --}}
            <div class="foto-placeholder"></div>
            <div class="foto-placeholder"></div>
          @endif
        </td>
      </tr>
    </tbody>
  </table>

  {{-- ── INGREDIENTES + PROCEDIMIENTO ───────────────────────────── --}}
  @php
    $ingredientes = $receta['ingredientes'] ?? [];
    $n = count($ingredientes);

    // Partir instrucciones en líneas individuales (filtrando líneas completamente vacías)
    $instruccionesRaw = trim($receta['instrucciones'] ?? '');
    $lineas = [];
    if ($instruccionesRaw !== '') {
        foreach (explode("\n", $instruccionesRaw) as $linea) {
            $linea = rtrim($linea);
            if (preg_match('/^(Nota:)(.*)/i', $linea, $m)) {
                $lineas[] = '<strong>' . e($m[1]) . '</strong>' . e($m[2]);
            } else {
                $lineas[] = e($linea);
            }
        }
    }
    $m = count($lineas);
    $totalFilas = max($n, $m, 1);
  @endphp
  <table class="ing" cellpadding="0" cellspacing="0" style="border-top: 1.5px solid #444;">
    <thead>
      <tr>
        <th style="width:38%;">Ingredientes:</th>
        <th style="width:10%;">Cantidad:</th>
        <th style="width:7%;">Unidad:</th>
        <th>Procedimiento:</th>
      </tr>
    </thead>
    <tbody>
      @for($i = 0; $i < $totalFilas; $i++)
        @php
          $ing   = $ingredientes[$i] ?? null;
          $linea = $lineas[$i] ?? '';
        @endphp
        <tr style="page-break-inside: avoid;">
          <td style="color:#1a1a1a; {{ !$ing ? 'border-bottom:none; border-right:none;' : '' }}">{{ $ing ? ($ing['producto_nombre'] ?? '—') : '' }}</td>
          <td class="num" style="color:#1a1a1a; {{ !$ing ? 'border-bottom:none; border-right:none;' : '' }}">{{ $ing ? number_format((float)($ing['cantidad_por_plato'] ?? 0), 3) : '' }}</td>
          <td style="color:#1a1a1a; {{ !$ing ? 'border-bottom:none; border-right:none;' : '' }}">{{ $ing ? ($ing['unidad'] ?? '') : '' }}</td>
          <td class="proc" style="border-left: 1.5px solid #444; border-bottom: none;">
            @if($linea !== '')
              {!! $linea !!}
            @elseif($i === 0 && $m === 0)
              <span style="color:#bbb; font-style:italic;">Sin instrucciones registradas.</span>
            @endif
          </td>
        </tr>
      @endfor
    </tbody>
  </table>

  {{-- ── FOOTER BULLETS ──────────────────────────────────────────── --}}
  @php
    $hayFooter = !empty($receta['rendimiento'])
              || (!empty($receta['platos_semana']) && $receta['platos_semana'] > 0)
              || !empty($receta['vida_util'])
              || !empty($receta['tiempo_vida']);
  @endphp
  @if($hayFooter)
  <div class="footer-section">
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
  </div>
  @endif

</div>
</body>
</html>
