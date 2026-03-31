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
.hdr td { border: none; color: #1a1a1a; }

.logo-cell {
  width: 84px;
  border-right: 1.5px solid #444;
  text-align: center;
  vertical-align: middle;
  padding: 8px;
  background: #fff;
}
/* Fondo negro circular para que el logo blanco/transparente sea visible */
.logo-wrap {
  display: inline-block;
  background: #1c1c1c;
  border-radius: 50%;
  width: 56px;
  height: 56px;
  line-height: 56px;
  text-align: center;
  vertical-align: middle;
}
.logo-wrap img { width: 44px; height: 44px; vertical-align: middle; }

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

.empty-top-cell {
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
  height: 200px;
  vertical-align: middle;
}
.foto-plato  { max-width: 220px; max-height: 170px; object-fit: contain; }

/* Placeholder cuando no hay foto */
.foto-placeholder {
  display: inline-block;
  width: 200px;
  height: 160px;
  border: 1.5px dashed #bbb;
  background: #f7f7f7;
  vertical-align: middle;
}

/* ── INGREDIENTS + PROCEDURE TABLE ───────────────────────────────── */
.ing-row td { border-top: 1.5px solid #444; padding: 0; }

.ing { width: 100%; border-collapse: collapse; }

.ing thead th {
  padding: 6px 10px;
  font-size: 10px;
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
  font-size: 10px;
  border-right: 1px solid #ccc;
  border-bottom: 1px solid #e8e8e8;
  vertical-align: top;
  color: #1a1a1a;
}
.ing tbody td.num { text-align: right; white-space: nowrap; color: #1a1a1a; }

/* Sub-receta: solo subrayado, sin cambiar el color */
.ing tbody td.sub-ing {
  text-decoration: underline;
  color: #1a1a1a;
}

.ing tbody td.proc {
  border-left: 1.5px solid #444;
  border-right: none;
  border-bottom: none;
  padding: 10px 12px;
  font-size: 10px;
  line-height: 1.9;
  vertical-align: top;
  color: #1a1a1a;
  min-height: 80px;
}

/* Texto de "sin instrucciones" en la columna de procedimiento */
.proc-vacio {
  color: #aaa;
  font-style: italic;
}

/* ── FOOTER BULLETS ──────────────────────────────────────────────── */
.footer { margin-top: 12px; font-size: 10.5px; line-height: 2.1; color: #1a1a1a; }
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
            {{-- Logo: fondo circular oscuro, spans 2 filas --}}
            <td class="logo-cell" rowspan="2">
              <div class="logo-wrap">
                <img src="{{ public_path('images/cadejo_logo.png') }}" alt="Cadejo" />
              </div>
            </td>
            {{-- Nombre empresa --}}
            <td class="company-cell">Cadejo Brewing Company</td>
            {{-- Top-right vacío --}}
            <td class="empty-top-cell"></td>
          </tr>
          <tr>
            {{-- Sub-header: sucursal | fecha --}}
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

  {{-- ── FOTO: siempre se muestra la fila; placeholder si no hay foto --}}
  <tr class="foto-row">
    <td>
      @if($foto_plato)
        <img src="{{ $foto_plato }}" class="foto-plato" alt="Foto del plato" />
      @else
        <div class="foto-placeholder"></div>
      @endif
    </td>
  </tr>

  {{-- ── INGREDIENTES + PROCEDIMIENTO ───────────────────────────── --}}
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
          @php
            $ingredientes = $receta['ingredientes'] ?? [];
            $n = count($ingredientes);

            // Formatear instrucciones: bold "Nota:" al inicio de línea
            $instruccionesRaw = trim($receta['instrucciones'] ?? '');
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

          @if($n > 0)
            @foreach($ingredientes as $idx => $ing)
            <tr>
              <td class="{{ $ing['es_sub_receta'] ? 'sub-ing' : '' }}" style="color:#1a1a1a;">
                {{ $ing['producto_nombre'] ?? '—' }}
              </td>
              <td class="num" style="color:#1a1a1a;">{{ number_format((float)($ing['cantidad_por_plato'] ?? 0), 3) }}</td>
              <td style="color:#1a1a1a;">{{ $ing['unidad'] ?? '' }}</td>
              @if($idx === 0)
              {{-- Procedimiento: rowspan cubre todos los ingredientes --}}
              <td class="proc" rowspan="{{ $n }}">
                @if($instruccionesHtml !== '')
                  {!! $instruccionesHtml !!}
                @else
                  <span class="proc-vacio">Sin instrucciones registradas.</span>
                @endif
              </td>
              @endif
            </tr>
            @endforeach
          @else
            {{-- Sin ingredientes --}}
            <tr>
              <td colspan="3" style="padding:12px; color:#999; font-style:italic; color:#1a1a1a;">
                Sin ingredientes registrados.
              </td>
              <td class="proc">
                @if($instruccionesHtml !== '')
                  {!! $instruccionesHtml !!}
                @else
                  <span class="proc-vacio">Sin instrucciones registradas.</span>
                @endif
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
