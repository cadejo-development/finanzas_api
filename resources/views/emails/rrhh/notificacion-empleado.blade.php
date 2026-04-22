<!DOCTYPE html>
<html lang="es" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:v="urn:schemas-microsoft-com:vml">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="color-scheme" content="light dark" />
  <meta name="supported-color-schemes" content="light dark" />
  <title>Notificacion RRHH</title>
  <!--[if mso]>
  <noscript>
    <xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
  </noscript>
  <![endif]-->
  <style>
    body { margin:0; padding:0; }
    table { border-collapse:collapse; }
    img { border:0; display:block; }

    @media (prefers-color-scheme: dark) {
      .body-bg    { background:#1c1917 !important; }
      .card-bg    { background:#292524 !important; }
      .body-text  { color:#d6d3d1 !important; }
      .sub-text   { color:#a8a29e !important; }
      .dtl-table  { border:1px solid #44403c !important; }
      .dtl-td     { color:#a8a29e !important; background:#1c1917 !important; border-bottom:1px solid #44403c !important; }
      .dtl-td-val { color:#f5f5f4 !important; background:#1c1917 !important; border-bottom:1px solid #44403c !important; }
      .dtl-even   { background:#292524 !important; }
      .emp-card   { background:#1c1917 !important; border:1px solid #44403c !important; }
      .emp-label  { color:#a8a29e !important; }
      .emp-value  { color:#f5f5f4 !important; }
      .info-box   { background:#451a03 !important; border-left:4px solid #f59e0b !important; }
      .info-text  { color:#fde68a !important; }
      .foot-sub   { color:#78716c !important; }
    }

    [data-ogsc] .body-bg    { background:#1c1917 !important; }
    [data-ogsc] .card-bg    { background:#292524 !important; }
    [data-ogsc] .body-text  { color:#d6d3d1 !important; }
    [data-ogsc] .sub-text   { color:#a8a29e !important; }
    [data-ogsc] .dtl-table  { border:1px solid #44403c !important; }
    [data-ogsc] .dtl-td     { color:#a8a29e !important; background:#1c1917 !important; border-bottom:1px solid #44403c !important; }
    [data-ogsc] .dtl-td-val { color:#f5f5f4 !important; background:#1c1917 !important; border-bottom:1px solid #44403c !important; }
    [data-ogsc] .dtl-even   { background:#292524 !important; }
    [data-ogsc] .emp-card   { background:#1c1917 !important; border:1px solid #44403c !important; }
    [data-ogsc] .emp-label  { color:#a8a29e !important; }
    [data-ogsc] .emp-value  { color:#f5f5f4 !important; }
    [data-ogsc] .info-box   { background:#451a03 !important; border-left:4px solid #f59e0b !important; }
    [data-ogsc] .info-text  { color:#fde68a !important; }
    [data-ogsc] .foot-sub   { color:#78716c !important; }
  </style>
</head>
<body class="body-bg" style="margin:0;padding:0;background:#f5f0e8;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" class="body-bg" style="background:#f5f0e8;padding:32px 16px;">
  <tr><td align="center">
    <table width="100%" cellpadding="0" cellspacing="0" class="card-bg" style="max-width:580px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.12);">

      {{-- Header --}}
      <tr>
        <td style="background:#1a1a1a;padding:32px 48px;text-align:center;">
          <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png" alt="Cadejo" width="80" style="display:block;margin:0 auto 16px;border-radius:50%;" />
          <p style="margin:0 0 6px 0;color:#f59e0b;font-size:11px;letter-spacing:3px;text-transform:uppercase;font-weight:600;">Cadejo Brewing Company</p>
          <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:1px;">Gestion de Talento</h1>
        </td>
      </tr>

      {{-- Banner --}}
      <tr>
        <td style="background:#b45309;padding:14px 48px;text-align:center;">
          <p style="margin:0;color:#ffffff;font-size:14px;font-weight:600;letter-spacing:1px;text-transform:uppercase;">{{ strtoupper($tipo) }}</p>
        </td>
      </tr>

      {{-- Saludo --}}
      <tr>
        <td class="card-bg" style="background:#ffffff;padding:32px 40px 10px;">
          <p class="body-text" style="margin:0 0 14px;color:#333333;font-size:16px;line-height:1.6;">
            Hola, <strong>{{ $destinatarioNombre }}</strong>
          </p>
          <p class="sub-text" style="margin:0 0 22px;color:#555555;font-size:15px;line-height:1.65;">
            {{ $mensaje }}
          </p>
        </td>
      </tr>

      {{-- Employee card --}}
      @if($empleadoNombre)
      <tr>
        <td class="card-bg" style="background:#ffffff;padding:0 40px 10px;">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td class="emp-card" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;">
                <p class="emp-label" style="margin:0 0 2px;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:1px;">Colaborador</p>
                <p class="emp-value" style="margin:0;color:#111827;font-size:15px;font-weight:700;">{{ $empleadoNombre }}</p>
              </td>
            </tr>
          </table>
        </td>
      </tr>
      @endif

      {{-- Detalles --}}
      @if(count($detalles))
      <tr>
        <td class="card-bg" style="background:#ffffff;padding:10px 40px 10px;">
          <table width="100%" cellpadding="0" cellspacing="0" class="dtl-table" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:8px;">
            @foreach(array_filter($detalles) as $label => $valor)
            <tr>
              <td class="dtl-td {{ $loop->even ? 'dtl-even' : '' }}" style="padding:12px 18px;font-size:13px;width:42%;color:#6b7280;background:{{ $loop->even ? '#ffffff' : '#fafafa' }};border-bottom:1px solid #f3f4f6;">{{ $label }}</td>
              <td class="dtl-td-val {{ $loop->even ? 'dtl-even' : '' }}" style="padding:12px 18px;font-size:13px;font-weight:600;text-align:right;color:#111827;background:{{ $loop->even ? '#ffffff' : '#fafafa' }};border-bottom:1px solid #f3f4f6;">{{ $valor }}</td>
            </tr>
            @endforeach
          </table>
        </td>
      </tr>
      @endif

      {{-- Aviso informativo --}}
      <tr>
        <td class="card-bg" style="background:#ffffff;padding:8px 40px 24px;">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td class="info-box" style="background:#fff8ec;border-left:4px solid #f59e0b;border-radius:4px;padding:14px 18px;">
                <p class="info-text" style="margin:0;color:#7a5000;font-size:13px;line-height:1.6;">Este correo es una notificacion automatica del sistema de Gestion de Talento. Si tienes dudas, contacta a tu jefe inmediato o al departamento de RRHH.</p>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      {{-- CTA --}}
      <tr>
        <td class="card-bg" style="background:#ffffff;padding:0 40px 36px;text-align:center;">
          <table cellpadding="0" cellspacing="0" align="center">
            <tr>
              <td style="background:#f59e0b;border-radius:8px;padding:12px 32px;">
                <a href="{{ $linkUrl }}" style="color:#1a1a1a;font-size:14px;font-weight:700;text-decoration:none;display:inline-block;letter-spacing:0.3px;">Ver en el sistema</a>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      {{-- Footer --}}
      <tr>
        <td style="background:#1a1a1a;padding:24px 40px;text-align:center;">
          <p style="margin:0 0 4px;color:#f59e0b;font-size:12px;font-weight:600;">Cadejo Brewing Company</p>
          <p class="foot-sub" style="margin:0;color:#6b7280;font-size:11px;">Este correo fue generado automaticamente por el modulo de Gestion de Talento.</p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
