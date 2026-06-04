<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: Helvetica, Arial, sans-serif;
      font-size: 11pt;
      color: #000;
      padding: 30px 50px;
      line-height: 1.5;
    }

    /* ── Cabecera ── */
    .header {
      width: 100%;
      border-bottom: 2px solid #000;
      padding-bottom: 12px;
      margin-bottom: 18px;
    }
    .header table { width: 100%; }
    .header td.logo-cell { width: 70px; vertical-align: middle; }
    .header td.company-cell { vertical-align: middle; padding-left: 12px; }
    .header img { width: 60px; height: 60px; }
    .header .company-name {
      font-size: 15pt;
      font-weight: bold;
      letter-spacing: 0.5px;
    }

    /* ── Título ── */
    .doc-title {
      font-size: 12pt;
      font-weight: bold;
      letter-spacing: 1px;
      margin-bottom: 14px;
    }

    /* ── Info básica ── */
    .info-block { margin-bottom: 14px; }
    .info-block p { margin: 3px 0; font-size: 10.5pt; }
    .info-block strong { font-weight: bold; }

    /* ── Separadores y secciones ── */
    hr {
      border: none;
      border-top: 1px solid #999;
      margin: 14px 0;
    }
    .section-title {
      font-weight: bold;
      font-size: 10.5pt;
      margin-bottom: 6px;
    }
    .section-body {
      font-size: 10.5pt;
      margin-bottom: 6px;
      text-align: justify;
    }
    ul.mejoras {
      margin: 6px 0 6px 22px;
      font-size: 10.5pt;
    }
    ul.mejoras li { margin: 4px 0; }

    /* ── Firmas ── */
    .signatures {
      margin-top: 55px;
    }
    .sig-row { margin-bottom: 30px; }
    .sig-row .sig-label { font-size: 10.5pt; font-weight: bold; }
    .sig-row .sig-line {
      display: inline-block;
      border-bottom: 1px solid #000;
      width: 220px;
      margin-left: 6px;
      vertical-align: bottom;
    }
  </style>
</head>
<body>

  {{-- ── Cabecera ── --}}
  <div class="header">
    <table>
      <tr>
        <td class="logo-cell">
          <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/public/logo2.png" alt="Logo Cadejo">
        </td>
        <td class="company-cell">
          <div class="company-name">Cadejo Brewing Company</div>
        </td>
      </tr>
    </table>
  </div>

  {{-- ── Título ── --}}
  <div class="doc-title">AMONESTACIÓN {{ strtoupper($tipoLabel) }}</div>

  {{-- ── Datos básicos ── --}}
  <div class="info-block">
    <p><strong>Fecha:</strong> {{ $fecha }}</p>
    <p><strong>Colaborador:</strong> {{ $empleadoNombre }}</p>
    <p><strong>Puesto:</strong> {{ $cargoNombre }}</p>
    <p><strong>Unidad:</strong> {{ $unidad }}</p>
  </div>

  {{-- ── Sección 1 ── --}}
  <hr>
  <p class="section-title">1. Clasificación de la falta</p>
  <p class="section-body">
    De acuerdo con las políticas internas de la organización, la situación descrita a continuación
    se clasifica como una <strong>{{ strtolower($tipoFaltaNombre) }}</strong>.
  </p>

  {{-- ── Sección 2 ── --}}
  <hr>
  <p class="section-title">2. Descripción de los hechos</p>
  <p class="section-body">{{ $descripcion }}</p>

  {{-- ── Sección 3 ── --}}
  <hr>
  <p class="section-title">3. Observación</p>
  @if($observacion)
    <p class="section-body">{{ $observacion }}</p>
  @else
    <p class="section-body">
      Es fundamental que toda actividad del colaborador se enmarque dentro de los lineamientos
      y políticas establecidos por la organización, a fin de garantizar un ambiente de trabajo
      adecuado y una operación eficiente.
    </p>
  @endif

  {{-- ── Sección 4 ── --}}
  <hr>
  <p class="section-title">4. Llamado de atención y mejora esperada</p>
  @if($accionTomada)
    <p class="section-body">{{ $accionTomada }}</p>
  @endif
  <p class="section-body">
    Se espera que, a partir de la fecha, el colaborador adopte las prácticas y conductas
    necesarias para corregir la situación descrita, dando cumplimiento a los lineamientos
    internos establecidos.
  </p>

  {{-- ── Sección 5 ── --}}
  <hr>
  <p class="section-title">5. Cierre</p>
  <p class="section-body">
    La presente amonestación tiene como finalidad corregir la conducta observada y fortalecer
    los procesos internos, evitando la recurrencia de situaciones similares y contribuyendo
    a una operación más eficiente.
  </p>

  <hr>

  {{-- ── Firmas ── --}}
  <div class="signatures">
    <div class="sig-row">
      <span class="sig-label">Firma del colaborador:</span>
      <span class="sig-line"></span>
    </div>
    <div class="sig-row">
      <span class="sig-label">Firma del {{ $jefeCargo }}:</span>
      <span class="sig-line"></span>
    </div>
  </div>

</body>
</html>
