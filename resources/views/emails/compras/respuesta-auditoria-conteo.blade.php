<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Respuesta a Auditoría de Conteo</title>
</head>
<body style="margin:0;padding:0;background:#f5f0e8;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f0e8;padding:32px 16px;">
  <tr>
    <td align="center">
      <table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.12);">

        {{-- Header --}}
        <tr>
          <td style="background:#1a1a1a;padding:32px 48px;text-align:center;">
            <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png" alt="Cadejo" width="80" style="display:block;margin:0 auto 16px;border-radius:50%;" />
            <p style="margin:0 0 6px 0;color:#f59e0b;font-size:11px;letter-spacing:3px;text-transform:uppercase;font-weight:600;">Cadejo Brewing Company</p>
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:1px;">Gestión de Operación</h1>
          </td>
        </tr>

        @php $aprobada = $decision === 'aprobada'; @endphp

        {{-- Banner --}}
        <tr>
          <td style="background:{{ $aprobada ? '#065f46' : '#7f1d1d' }};padding:14px 48px;text-align:center;">
            <p style="margin:0;color:#ffffff;font-size:14px;font-weight:600;letter-spacing:1px;text-transform:uppercase;">
              Auditoría de Conteo {{ $aprobada ? 'Aprobada ✓' : 'Rechazada ✗' }}
            </p>
          </td>
        </tr>

        {{-- Cuerpo --}}
        <tr>
          <td style="padding:32px 40px 16px;">
            <p style="margin:0 0 12px;color:#333333;font-size:15px;line-height:1.65;">
              <strong>{{ $respondente }}</strong> ha revisado la auditoría del conteo mensual de
              <strong>{{ $sucursalNombre }}</strong> ({{ $fecha }}) y la ha
              <strong style="color:{{ $aprobada ? '#059669' : '#dc2626' }};">{{ $aprobada ? 'aprobado' : 'rechazado' }}</strong>.
            </p>
          </td>
        </tr>

        {{-- Detalles --}}
        <tr>
          <td style="padding:0 40px 16px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
              <tr style="background:#fafafa;">
                <td style="padding:12px 18px;font-size:13px;color:#6b7280;width:42%;border-bottom:1px solid #f3f4f6;">Sucursal</td>
                <td style="padding:12px 18px;font-size:13px;font-weight:600;text-align:right;color:#111827;border-bottom:1px solid #f3f4f6;">{{ $sucursalNombre }}</td>
              </tr>
              <tr style="background:#ffffff;">
                <td style="padding:12px 18px;font-size:13px;color:#6b7280;border-bottom:1px solid #f3f4f6;">Fecha de conteo</td>
                <td style="padding:12px 18px;font-size:13px;font-weight:600;text-align:right;color:#111827;border-bottom:1px solid #f3f4f6;">{{ $fecha }}</td>
              </tr>
              <tr style="background:#fafafa;">
                <td style="padding:12px 18px;font-size:13px;color:#6b7280;">Decisión</td>
                <td style="padding:12px 18px;font-size:14px;font-weight:700;text-align:right;color:{{ $aprobada ? '#059669' : '#dc2626' }};">
                  {{ $aprobada ? '✓ Aprobada' : '✗ Rechazada' }}
                </td>
              </tr>
            </table>
          </td>
        </tr>

        @if($comentarios)
        <tr>
          <td style="padding:0 40px 24px;">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:{{ $aprobada ? '#f0fdf4' : '#fef2f2' }};border-left:4px solid {{ $aprobada ? '#059669' : '#dc2626' }};border-radius:4px;padding:14px 18px;">
                  <p style="margin:0 0 4px;color:{{ $aprobada ? '#065f46' : '#7f1d1d' }};font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Comentarios</p>
                  <p style="margin:0;color:{{ $aprobada ? '#065f46' : '#7f1d1d' }};font-size:13px;line-height:1.6;">{{ $comentarios }}</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        @endif

        {{-- CTA --}}
        <tr>
          <td style="padding:0 40px 36px;text-align:center;">
            <table cellpadding="0" cellspacing="0" align="center">
              <tr>
                <td style="background:#1d4ed8;border-radius:8px;padding:12px 32px;">
                  <a href="https://gestion-operaciones.cervezacadejo.com/inventario" style="color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;display:inline-block;letter-spacing:0.3px;">Ver auditoría en el sistema</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Footer --}}
        <tr>
          <td style="background:#1a1a1a;padding:24px 40px;text-align:center;">
            <p style="margin:0 0 4px;color:#f59e0b;font-size:12px;font-weight:600;">Cadejo Brewing Company</p>
            <p style="margin:0;color:#6b7280;font-size:11px;">Este correo fue generado automáticamente por el módulo de Gestión de Operación.</p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
