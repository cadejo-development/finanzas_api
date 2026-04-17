<!DOCTYPE html>
<html lang="es" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:v="urn:schemas-microsoft-com:vml">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="color-scheme" content="light dark" />
  <meta name="supported-color-schemes" content="light dark" />
  <title>Notificacion de accion de personal</title>
  <!--[if mso]>
  <noscript>
    <xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
  </noscript>
  <![endif]-->
  <style>
    body { margin:0; padding:0; }
    table { border-collapse:collapse; }
    img { border:0; display:block; }
    a { color:inherit; }

    .body-bg      { background:#f5f0e8 !important; }
    .card-bg      { background:#ffffff !important; }
    .header-bg    { background:#1a1a1a !important; }
    .banner-bg    { background:#1d4ed8 !important; }
    .label-color  { color:#6b7280 !important; }
    .value-color  { color:#111827 !important; }
    .body-text    { color:#333333 !important; }
    .sub-text     { color:#555555 !important; }
    .row-odd-bg   { background:#fafafa !important; }
    .row-even-bg  { background:#ffffff !important; }
    .row-border   { border-bottom:1px solid #f3f4f6 !important; }
    .table-border { border:1px solid #e5e7eb !important; }
    .emp-card-bg  { background:#f9fafb !important; border:1px solid #e5e7eb !important; }
    .info-bg      { background:#eff6ff !important; border-left:4px solid #3b82f6 !important; }
    .info-text    { color:#1e40af !important; }
    .footer-bg    { background:#1a1a1a !important; }
    .footer-text  { color:#6b7280 !important; }
    .brand-text   { color:#f59e0b !important; }
    .cta-bg       { background:#f59e0b !important; }
    .cta-text     { color:#1a1a1a !important; }

    @media (prefers-color-scheme: dark) {
      .body-bg      { background:#1c1917 !important; }
      .card-bg      { background:#292524 !important; }
      .label-color  { color:#a8a29e !important; }
      .value-color  { color:#f5f5f4 !important; }
      .body-text    { color:#d6d3d1 !important; }
      .sub-text     { color:#a8a29e !important; }
      .row-odd-bg   { background:#1c1917 !important; }
      .row-even-bg  { background:#292524 !important; }
      .row-border   { border-bottom:1px solid #44403c !important; }
      .table-border { border:1px solid #44403c !important; }
      .emp-card-bg  { background:#1c1917 !important; border:1px solid #44403c !important; }
      .info-bg      { background:#172554 !important; border-left:4px solid #3b82f6 !important; }
      .info-text    { color:#bfdbfe !important; }
      .footer-text  { color:#78716c !important; }
    }

    [data-ogsc] .body-bg      { background:#1c1917 !important; }
    [data-ogsc] .card-bg      { background:#292524 !important; }
    [data-ogsc] .label-color  { color:#a8a29e !important; }
    [data-ogsc] .value-color  { color:#f5f5f4 !important; }
    [data-ogsc] .body-text    { color:#d6d3d1 !important; }
    [data-ogsc] .sub-text     { color:#a8a29e !important; }
    [data-ogsc] .row-odd-bg   { background:#1c1917 !important; }
    [data-ogsc] .row-even-bg  { background:#292524 !important; }
    [data-ogsc] .row-border   { border-bottom:1px solid #44403c !important; }
    [data-ogsc] .table-border { border:1px solid #44403c !important; }
    [data-ogsc] .emp-card-bg  { background:#1c1917 !important; border:1px solid #44403c !important; }
    [data-ogsc] .info-bg      { background:#172554 !important; border-left:4px solid #3b82f6 !important; }
    [data-ogsc] .info-text    { color:#bfdbfe !important; }
    [data-ogsc] .footer-text  { color:#78716c !important; }
  </style>
</head>
<body class="body-bg" style="margin:0;padding:0;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" class="body-bg" style="padding:32px 16px;">
  <tr><td align="center">
    <table width="100%" cellpadding="0" cellspacing="0" class="card-bg" style="max-width:580px;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.12);">

      {{-- Header --}}
      <tr>
        <td class="header-bg" style="padding:32px 48px;text-align:center;">
          <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png" alt="Cadejo" width="80" style="display:block;margin:0 auto 16px;border-radius:50%;" />
          <p class="brand-text" style="margin:0 0 6px 0;font-size:11px;letter-spacing:3px;text-transform:uppercase;font-weight:600;">Cadejo Brewing Company</p>
          <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:1px;">Gestion de Talento</h1>
        </td>
      </tr>

      {{-- Banner --}}
      <tr>
        <td class="banner-bg" style="padding:14px 48px;text-align:center;">
          <p style="margin:0;color:#ffffff;font-size:14px;font-weight:600;letter-spacing:1px;text-transform:uppercase;">Nuevo Registro</p>
        </td>
      </tr>

      {{-- Cuerpo --}}
      <tr>
        <td style="padding:32px 40px 10px;">
          <p class="body-text" style="margin:0 0 14px;font-size:16px;line-height:1.6;">
            Hola, <strong>{{ $supervisorNombre }}</strong>
          </p>
          <p class="sub-text" style="margin:0 0 22px;font-size:15px;line-height:1.65;">
            Se ha registrado una nueva <strong>{{ strtolower($tipo) }}</strong> para el siguiente colaborador a tu cargo:
          </p>
          <p style="margin:0 0 20px;">
            <span style="display:inline-block;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700;letter-spacing:0.5px;padding:4px 14px;border-radius:20px;border:1px solid #bfdbfe;">{{ strtoupper($tipo) }}</span>
          </p>
        </td>
      </tr>

      {{-- Employee card --}}
      <tr>
        <td style="padding:0 40px 10px;">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td class="emp-card-bg" style="border-radius:8px;padding:14px 18px;">
                <p class="label-color" style="margin:0 0 2px;font-size:11px;text-transform:uppercase;letter-spacing:1px;">Colaborador</p>
                <p class="value-color" style="margin:0;font-size:15px;font-weight:700;">{{ $empleadoNombre }}</p>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      {{-- Detalles --}}
      @if(count($detalles))
      <tr>
        <td style="padding:10px 40px 10px;">
          <table width="100%" cellpadding="0" cellspacing="0" class="table-border" style="border-radius:8px;overflow:hidden;margin-bottom:8px;">
            @foreach(array_filter($detalles) as $label => $valor)
            <tr>
              <td class="label-color {{ $loop->odd ? 'row-odd-bg' : 'row-even-bg' }} row-border" style="padding:12px 18px;font-size:13px;width:42%;">{{ $label }}</td>
              <td class="value-color {{ $loop->odd ? 'row-odd-bg' : 'row-even-bg' }} row-border" style="padding:12px 18px;font-size:13px;font-weight:600;text-align:right;">{{ $valor }}</td>
            </tr>
            @endforeach
          </table>
        </td>
      </tr>
      @endif

      {{-- Aviso informativo --}}
      <tr>
        <td style="padding:8px 40px 24px;">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td class="info-bg" style="border-radius:4px;padding:14px 18px;">
                <p class="info-text" style="margin:0;font-size:13px;line-height:1.6;">Este es un correo informativo. No se requiere ninguna accion de tu parte.</p>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      {{-- CTA --}}
      <tr>
        <td style="padding:0 40px 36px;text-align:center;">
          <table cellpadding="0" cellspacing="0" align="center">
            <tr>
              <td class="cta-bg" style="border-radius:8px;padding:12px 32px;">
                <a href="{{ $linkUrl }}" class="cta-text" style="font-size:14px;font-weight:700;text-decoration:none;display:inline-block;letter-spacing:0.3px;">Ver en el sistema</a>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      {{-- Footer --}}
      <tr>
        <td class="footer-bg" style="padding:24px 40px;text-align:center;">
          <p class="brand-text" style="margin:0 0 4px;font-size:12px;font-weight:600;">Cadejo Brewing Company</p>
          <p class="footer-text" style="margin:0;font-size:11px;">Este correo fue generado automaticamente por el modulo de Gestion de Talento.</p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
