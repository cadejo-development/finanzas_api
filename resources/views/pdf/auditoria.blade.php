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
  line-height: 1.4;
}

@page {
  margin: 8mm 10mm;
}

.page-border {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  border: 1.5px solid #444;
}

.wrap { padding: 10mm 12mm 10mm 12mm; }

/* ── HEADER ─────────────────────────────────────────────────── */
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
  font-size: 13px;
  font-weight: bold;
  text-transform: uppercase;
  letter-spacing: 2px;
  color: #1a1a1a;
}

.top-right-cell {
  width: 130px;
  border-left: 1.5px solid #444;
  padding: 7px 9px;
  font-size: 9px;
  vertical-align: middle;
  color: #1a1a1a;
}
.top-right-cell .label { color: #666; font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
.top-right-cell .val   { font-weight: bold; font-size: 10px; }

.location-cell {
  border-top: 1.5px solid #444;
  padding: 4px 10px;
  font-size: 9.5px;
  vertical-align: middle;
  color: #1a1a1a;
}
.fecha-cell {
  width: 130px;
  border-top: 1.5px solid #444;
  border-left: 1.5px solid #444;
  padding: 4px 9px;
  font-size: 9px;
  text-align: right;
  white-space: nowrap;
  vertical-align: middle;
  color: #1a1a1a;
}

/* ── TITULO / META ───────────────────────────────────────────── */
.titulo-row td {
  border-top: 1.5px solid #444;
  padding: 7px 14px;
  font-size: 11.5px;
  font-weight: bold;
  color: #1a1a1a;
}
.meta-row td {
  border-top: 1px solid #ccc;
  padding: 3px 0;
  vertical-align: top;
}
.meta-table { width: 100%; border-collapse: collapse; margin: 0; }
.meta-cell  { padding: 5px 10px; font-size: 9px; }
.meta-cell .lbl { color: #666; font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; display: block; }
.meta-cell .val { font-weight: bold; font-size: 9.5px; }
.meta-divider { border-right: 1px solid #ddd; }

/* ── SCORE BADGE ─────────────────────────────────────────────── */
.score-row td { border-top: 1.5px solid #444; padding: 8px 14px; }
.score-inner {
  display: table;
  width: 100%;
}
.score-pct {
  display: table-cell;
  font-size: 28px;
  font-weight: bold;
  vertical-align: middle;
  width: 80px;
}
.score-clasif {
  display: table-cell;
  vertical-align: middle;
  padding-left: 10px;
}
.score-clasif .clasif-label { font-size: 13px; font-weight: bold; }
.score-clasif .clasif-sub   { font-size: 8.5px; color: #666; margin-top: 2px; }
.score-conteo {
  display: table-cell;
  vertical-align: middle;
  text-align: right;
  font-size: 9px;
  color: #444;
}
.score-conteo span { display: inline-block; margin-left: 10px; }

/* ── SECCIONES ───────────────────────────────────────────────── */
.sec-wrap { border-top: 1.5px solid #444; }
.sec-header {
  background: #f5f5f5;
  border-bottom: 1px solid #ccc;
  padding: 5px 10px;
  font-size: 9.5px;
  font-weight: bold;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #333;
}
.sec-table { width: 100%; border-collapse: collapse; }
.sec-table td {
  padding: 3px 8px;
  font-size: 9px;
  border-bottom: 1px solid #f0f0f0;
  vertical-align: middle;
  color: #1a1a1a;
}
.ic { width: 16px; text-align: center; font-size: 9px; }
.ic-ok  { color: #16a34a; font-weight: bold; }
.ic-no  { color: #dc2626; font-weight: bold; }
.ic-na  { color: #9ca3af; }
.ic-pen { color: #d1d5db; }
.obs  { color: #555; font-style: italic; font-size: 8.5px; }

/* ── FOTOS POR SECCIÓN ───────────────────────────────────────── */
.sec-fotos { border-top: 1px dashed #ccc; padding: 6px 8px; page-break-inside: avoid; }
.sec-fotos-title { font-size: 8px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; }
.foto-grid { width: 100%; border-collapse: collapse; }
.foto-cell { padding: 2px 4px; text-align: center; vertical-align: top; width: 25%; }
.foto-img  { width: 118px; height: 95px; object-fit: cover; border: 1px solid #ddd; }

/* ── OBSERVACIONES / COMENTARIO ──────────────────────────────── */
.obs-block { border-top: 1.5px solid #444; padding: 8px 14px; }
.obs-block .obs-title { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; color: #555; margin-bottom: 4px; }
.obs-block .obs-text  { font-size: 10px; color: #1a1a1a; line-height: 1.5; }
.resp-block { border-top: 1.5px solid #444; padding: 8px 14px; background: #f8f9ff; }
.resp-block .obs-title { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; color: #3b4fa0; margin-bottom: 4px; }
.resp-block .obs-text  { font-size: 10px; color: #1a1a1a; line-height: 1.5; }
.resp-meta { font-size: 8.5px; color: #666; margin-top: 5px; }

/* ── PAGE BREAKS ────────────────────────────────────────────── */
.sec-header  { page-break-after: avoid; }
.sec-table   { page-break-inside: auto; }
.sec-table tr { page-break-inside: avoid; }
.sec-fotos   { page-break-inside: avoid; }
.obs-block   { page-break-inside: avoid; }
.resp-block  { page-break-inside: avoid; }

/* ── COLOR SCORE ────────────────────────────────────────────── */
.score-excelente { color: #15803d; }
.score-muybueno  { color: #0369a1; }
.score-bueno     { color: #1d4ed8; }
.score-aceptable { color: #b45309; }
.score-deficiente{ color: #b91c1c; }
</style>
</head>
<body>

<div class="page-border"></div>

<div class="wrap">

  {{-- ── HEADER ──────────────────────────────────────────────── --}}
  <table class="hdr" cellpadding="0" cellspacing="0">
    <tbody>
      <tr>
        <td class="logo-cell" rowspan="2">
          <div class="logo-wrap">
            <img src="{{ public_path('images/cadejo_logo.png') }}" alt="Cadejo" />
          </div>
        </td>
        <td class="company-cell">Cadejo Brewing Company</td>
        <td class="top-right-cell" rowspan="2">
          <div class="label">Tipo de auditoría</div>
          <div class="val">{{ $auditoria['tipo'] === 'calidad' ? 'Calidad' : 'Operaciones' }}</div>
          <div style="margin-top:5px;">
            <div class="label">ID</div>
            <div class="val">#{{ $auditoria['id'] }}</div>
          </div>
        </td>
      </tr>
      <tr>
        <td class="location-cell">{{ $auditoria['sucursal'] ?? 'Cadejo Brewing Company' }}</td>
      </tr>
      <tr>
        <td colspan="2" class="location-cell" style="font-size:8.5px; color:#888;">
          Evaluador: {{ $auditoria['evaluador_nombre'] ?? '—' }}
        </td>
        <td class="fecha-cell">
          Fecha: {{ \Carbon\Carbon::parse($auditoria['fecha'])->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}<br>
          Hora: {{ $auditoria['hora'] ?? '' }}
        </td>
      </tr>
    </tbody>
  </table>

  {{-- ── TÍTULO ───────────────────────────────────────────────── --}}
  <table style="width:100%; border-collapse:collapse;">
    <tbody>
      <tr class="titulo-row">
        <td>
          AUDITORÍA DE {{ strtoupper($auditoria['tipo'] === 'calidad' ? 'CALIDAD' : 'OPERACIONES') }}
          @if(!empty($auditoria['receta']))
            &nbsp;—&nbsp;{{ strtoupper($auditoria['receta']) }}
          @endif
        </td>
      </tr>
    </tbody>
  </table>

  {{-- ── META DATOS ───────────────────────────────────────────── --}}
  <table style="width:100%; border-collapse:collapse;">
    <tbody>
      <tr>
        <td style="padding:0; border-top: 1px solid #ccc;">
          <table class="meta-table" cellpadding="0" cellspacing="0">
            <tr>
              <td class="meta-cell meta-divider">
                <span class="lbl">Responsable</span>
                <span class="val">{{ $auditoria['responsable_nombre'] ?? '—' }}</span>
              </td>
              <td class="meta-cell meta-divider">
                <span class="lbl">Estación</span>
                <span class="val">{{ $auditoria['estacion'] ?? '—' }}</span>
              </td>
              <td class="meta-cell meta-divider">
                <span class="lbl">Estado</span>
                <span class="val">{{ ucfirst(str_replace('_', ' ', $auditoria['estado'] ?? '')) }}</span>
              </td>
              @if(!empty($auditoria['notas']))
              <td class="meta-cell">
                <span class="lbl">Notas</span>
                <span class="val">{{ $auditoria['notas'] }}</span>
              </td>
              @endif
            </tr>
          </table>
        </td>
      </tr>
    </tbody>
  </table>

  {{-- ── SCORE ────────────────────────────────────────────────── --}}
  @php
    $calif       = $auditoria['calificacion'];
    $clasif      = $auditoria['clasificacion'] ?? null;
    $colorClass  = match(true) {
        $clasif === 'Excelente' || $clasif === 'Muy Bueno' => 'score-excelente',
        $clasif === 'Bueno'     => 'score-bueno',
        $clasif === 'Aceptable' => 'score-aceptable',
        $clasif === 'Deficiente'=> 'score-deficiente',
        default                 => '',
    };
    // Conteo global de criterios
    $totalItems   = 0;
    $cumpleCount  = 0;
    $noCumple     = 0;
    $naCount      = 0;
    $sinEvaluar   = 0;
    foreach ($secciones as $sec) {
        foreach ($sec['items'] as $item) {
            $totalItems++;
            match($item['resultado']) {
                'cumple'    => $cumpleCount++,
                'no_cumple' => $noCumple++,
                'na'        => $naCount++,
                default     => $sinEvaluar++,
            };
        }
    }
  @endphp
  <table style="width:100%; border-collapse:collapse;">
    <tbody>
      <tr class="score-row">
        <td>
          <div class="score-inner">
            <div class="score-pct {{ $colorClass }}">
              {{ $calif !== null ? number_format((float)$calif, 1) . '%' : '—' }}
            </div>
            <div class="score-clasif">
              <div class="clasif-label {{ $colorClass }}">{{ $clasif ?? 'Sin evaluar' }}</div>
              <div class="clasif-sub">Resultado de la auditoría</div>
            </div>
            <div class="score-conteo">
              <span style="color:#15803d;">&#10003; {{ $cumpleCount }} cumple</span>
              <span style="color:#dc2626;">&#10007; {{ $noCumple }} no cumple</span>
              <span style="color:#9ca3af;">N/A {{ $naCount }}</span>
              @if($sinEvaluar > 0)
              <span style="color:#d1d5db;">{{ $sinEvaluar }} pendiente</span>
              @endif
            </div>
          </div>
        </td>
      </tr>
    </tbody>
  </table>

  {{-- ── SECCIONES CON CRITERIOS ─────────────────────────────── --}}
  @foreach($secciones as $si => $sec)
  <div class="sec-wrap">
    <div class="sec-header">{{ $si + 1 }}. {{ $sec['categoria'] }}</div>
    <table class="sec-table" cellpadding="0" cellspacing="0">
      <tbody>
        @foreach($sec['items'] as $ii => $item)
        @php
          $resultado = $item['resultado'] ?? null;
          switch($resultado) {
            case 'cumple':    $icono = '&#10003;'; $icoClass = 'ic-ok';  break;
            case 'no_cumple': $icono = '&#10007;'; $icoClass = 'ic-no';  break;
            case 'na':        $icono = 'N/A';      $icoClass = 'ic-na';  break;
            default:          $icono = '—';        $icoClass = 'ic-pen'; break;
          }
        @endphp
        <tr>
          <td class="ic {{ $icoClass }}">{!! $icono !!}</td>
          <td style="font-size:9px; color:#333; width:12px; text-align:right; padding-right:4px;">{{ $ii + 1 }}.</td>
          <td>{{ $item['nombre'] }}</td>
          @if(!empty($item['observaciones']))
          <td class="obs">{{ $item['observaciones'] }}</td>
          @else
          <td></td>
          @endif
        </tr>
        @endforeach
      </tbody>
    </table>

    {{-- Fotos de sección --}}
    @if(!empty($sec['fotos']) && count($sec['fotos']) > 0)
    @php $fotosChunks = array_chunk($sec['fotos'], 4); @endphp
    <div class="sec-fotos">
      <div class="sec-fotos-title">Evidencia fotográfica</div>
      <table class="foto-grid" cellpadding="0" cellspacing="0">
        @foreach($fotosChunks as $fila)
        <tr style="page-break-inside: avoid;">
          @foreach($fila as $foto)
          <td class="foto-cell">
            <img src="{{ $foto }}" class="foto-img" alt="Foto" />
          </td>
          @endforeach
          @for($p = count($fila); $p < 4; $p++)
          <td class="foto-cell"></td>
          @endfor
        </tr>
        @endforeach
      </table>
    </div>
    @endif
  </div>
  @endforeach

  {{-- ── OBSERVACIONES GENERALES ─────────────────────────────── --}}
  @if(!empty($auditoria['observaciones_generales']))
  <div class="obs-block">
    <div class="obs-title">Observaciones generales</div>
    <div class="obs-text">{{ $auditoria['observaciones_generales'] }}</div>
  </div>
  @endif

  {{-- ── COMENTARIO DEL GERENTE (calidad respondida) ──────────── --}}
  @if(!empty($auditoria['comentario_gerente']))
  <div class="resp-block">
    <div class="obs-title" style="color:#3b4fa0;">Comentario del Gerente / Chef</div>
    <div class="obs-text">{{ $auditoria['comentario_gerente'] }}</div>
    <div class="resp-meta">
      Respondido por: {{ $auditoria['respondido_por_nombre'] ?? '—' }}
      @if(!empty($auditoria['respondido_at']))
        &nbsp;·&nbsp;{{ \Carbon\Carbon::parse($auditoria['respondido_at'])->setTimezone('America/El_Salvador')->format('d/m/Y H:i') }}
      @endif
    </div>
  </div>
  @endif

</div>
</body>
</html>
