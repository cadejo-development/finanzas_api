<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Solicitud de Carga a BRILO</title>
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

        {{-- Banner --}}
        <tr>
          <td style="background:#b45309;padding:14px 48px;text-align:center;">
            <p style="margin:0;color:#ffffff;font-size:14px;font-weight:600;letter-spacing:1px;text-transform:uppercase;">Solicitud de Carga a BRILO</p>
          </td>
        </tr>

        {{-- Cuerpo --}}
        <tr>
          <td style="padding:32px 40px 16px;">
            <p style="margin:0 0 12px;color:#333333;font-size:15px;line-height:1.65;">
              <strong>{{ $solicitadoPorNombre }}</strong> ha enviado una solicitud de carga de recetas al sistema BRILO.
            </p>
          </td>
        </tr>

        {{-- Detalles --}}
        <tr>
          <td style="padding:0 40px 16px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
              <tr style="background:#fafafa;">
                <td style="padding:12px 18px;font-size:13px;color:#6b7280;width:42%;border-bottom:1px solid #f3f4f6;">Solicitado por</td>
                <td style="padding:12px 18px;font-size:13px;font-weight:600;text-align:right;color:#111827;border-bottom:1px solid #f3f4f6;">{{ $solicitadoPorNombre }}</td>
              </tr>
              <tr style="background:#ffffff;">
                <td style="padding:12px 18px;font-size:13px;color:#6b7280;border-bottom:1px solid #f3f4f6;">Fecha requerida</td>
                <td style="padding:12px 18px;font-size:13px;font-weight:600;text-align:right;color:#d97706;border-bottom:1px solid #f3f4f6;">{{ $fechaRequerida }}</td>
              </tr>
              <tr style="background:#fafafa;">
                <td style="padding:12px 18px;font-size:13px;color:#6b7280;">Total de recetas</td>
                <td style="padding:12px 18px;font-size:13px;font-weight:600;text-align:right;color:#111827;">{{ $totalRecetas }}</td>
              </tr>
            </table>
          </td>
        </tr>

        @if($nota)
        <tr>
          <td style="padding:0 40px 16px;">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:#fff8ec;border-left:4px solid #f59e0b;border-radius:4px;padding:14px 18px;">
                  <p style="margin:0 0 4px;color:#7a5000;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Nota del solicitante</p>
                  <p style="margin:0;color:#7a5000;font-size:13px;line-height:1.6;">{{ $nota }}</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        @endif

        {{-- Lista de recetas --}}
        <tr>
          <td style="padding:0 40px 16px;">
            <p style="margin:0 0 8px;color:#374151;font-size:13px;font-weight:600;">Recetas a cargar:</p>
            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
              @foreach(array_slice($recetaNombres, 0, 15) as $idx => $nombre)
              <tr style="background:{{ $loop->odd ? '#fafafa' : '#ffffff' }};">
                <td style="padding:8px 18px;font-size:13px;color:#374151;border-bottom:1px solid #f3f4f6;">
                  <span style="color:#9ca3af;margin-right:8px;">{{ $idx + 1 }}.</span>{{ $nombre }}
                </td>
              </tr>
              @endforeach
              @if(count($recetaNombres) > 15)
              <tr style="background:#f9fafb;">
                <td style="padding:8px 18px;font-size:12px;color:#9ca3af;font-style:italic;">
                  ... y {{ count($recetaNombres) - 15 }} receta(s) más. Ver detalle en el sistema.
                </td>
              </tr>
              @endif
            </table>
          </td>
        </tr>

        {{-- CTA --}}
        <tr>
          <td style="padding:16px 40px 36px;text-align:center;">
            <table cellpadding="0" cellspacing="0" align="center">
              <tr>
                <td style="background:#f59e0b;border-radius:8px;padding:12px 32px;">
                  <a href="{{ $linkUrl }}" style="color:#1a1a1a;font-size:14px;font-weight:700;text-decoration:none;display:inline-block;letter-spacing:0.3px;">Ver y Exportar en el sistema</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Footer --}}
        <tr>
          <td style="background:#1a1a1a;padding:24px 40px;text-align:center;">
            <p style="margin:0 0 4px;color:#f59e0b;font-size:12px;font-weight:600;">Cadejo Brewing Company</p>
            <p style="margin:0;color:#6b7280;font-size:11px;">Este correo fue generado automaticamente por el modulo de Gestion de Operacion.</p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
