<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: Helvetica, Arial, sans-serif;
      font-size: 10pt;
      color: #000;
      padding: 25px 45px;
      line-height: 1.55;
    }

    /* ── Header ── */
    .header {
      width: 100%;
      border-bottom: 2px solid #000;
      padding-bottom: 10px;
      margin-bottom: 14px;
    }
    .header table { width: 100%; }
    .header td.logo-cell { width: 70px; vertical-align: middle; }
    .header td.title-cell { vertical-align: middle; padding-left: 14px; }
    .header img { width: 55px; height: 55px; }
    .header .company { font-size: 14pt; font-weight: bold; }
    .header .doc-type { font-size: 10pt; color: #555; margin-top: 2px; }

    /* ── Contenido ── */
    .body-content {
      margin-top: 6px;
    }
    .body-content p {
      margin: 6px 0;
      text-align: justify;
    }
    .body-content h1, .body-content h2, .body-content h3 {
      font-size: 11pt;
      font-weight: bold;
      margin: 12px 0 6px 0;
      text-align: center;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .body-content h3 {
      text-align: left;
      font-size: 10pt;
    }
    .body-content ul, .body-content ol {
      margin: 6px 0 6px 22px;
    }
    .body-content li { margin: 3px 0; }
    .body-content table {
      width: 100%;
      border-collapse: collapse;
      margin: 8px 0;
      font-size: 9.5pt;
    }
    .body-content table th,
    .body-content table td {
      border: 1px solid #888;
      padding: 4px 7px;
    }
    .body-content table th {
      background: #f0f0f0;
      font-weight: bold;
      text-align: center;
    }
    .body-content strong { font-weight: bold; }
    .body-content em { font-style: italic; }
    .body-content hr {
      border: none;
      border-top: 1px solid #ccc;
      margin: 10px 0;
    }
    .body-content .center { text-align: center; }
    .body-content .firma-block {
      margin-top: 40px;
    }
    .body-content .firma-row {
      display: inline-block;
      width: 48%;
      vertical-align: top;
      text-align: center;
      padding-top: 10px;
    }
    .body-content .firma-linea {
      border-top: 1px solid #000;
      width: 80%;
      margin: 0 auto 4px auto;
      padding-top: 4px;
    }
    .body-content .firma-label {
      font-size: 9pt;
      color: #333;
    }

    /* ── Footer ── */
    .footer {
      margin-top: 18px;
      border-top: 1px solid #ccc;
      padding-top: 6px;
      font-size: 8pt;
      color: #666;
      text-align: center;
    }
  </style>
</head>
<body>

  {{-- ── Header ── --}}
  <div class="header">
    <table>
      <tr>
        <td class="logo-cell">
          <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/public/logo2.png" alt="Logo Cadejo">
        </td>
        <td class="title-cell">
          <div class="company">Cadejo Brewing Company</div>
          <div class="doc-type">{{ $tipoContrato }}</div>
        </td>
      </tr>
    </table>
  </div>

  {{-- ── Cuerpo del contrato ── --}}
  <div class="body-content">
    {!! $contenido !!}
  </div>

  {{-- ── Footer ── --}}
  <div class="footer">
    Cadejo Brewing Company &bull; David Arthur Falkenstein Algara &bull; NIT: 0614-120411-105-11
  </div>

</body>
</html>
