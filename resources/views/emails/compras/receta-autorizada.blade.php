<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Receta Autorizada</title>
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
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:1px;">Gestión de Recetas</h1>
          </td>
        </tr>

        {{-- Banner --}}
        <tr>
          <td style="background:#276749;padding:14px 48px;text-align:center;">
            <p style="margin:0;color:#ffffff;font-size:14px;font-weight:600;letter-spacing:1px;text-transform:uppercase;">✅ Receta Autorizada — Pendiente de Actualización</p>
          </td>
        </tr>

        {{-- Cuerpo --}}
        <tr>
          <td style="padding:32px 40px 16px;">
            <p style="margin:0 0 16px;color:#333333;font-size:15px;line-height:1.65;">
              <strong>{{ $autorizadoPor }}</strong> autorizó la siguiente receta el <strong>{{ $autorizadoEn }}</strong>. Se adjunta el PDF con los detalles actualizados para que pueda ser actualizada en el sistema.
            </p>
          </td>
        </tr>

        {{-- Detalles de la receta --}}
        <tr>
          <td style="padding:0 40px 24px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
              <tr style="background:#fafafa;">
                <td style="padding:12px 18px;font-size:13px;color:#6b7280;width:38%;border-bottom:1px solid #f3f4f6;">Código</td>
                <td style="padding:12px 18px;font-size:13px;font-weight:600;text-align:right;color:#111827;border-bottom:1px solid #f3f4f6;">{{ $recetaCodigo ?: '—' }}</td>
              </tr>
              <tr style="background:#ffffff;">
                <td style="padding:12px 18px;font-size:13px;color:#6b7280;border-bottom:1px solid #f3f4f6;">Nombre</td>
                <td style="padding:12px 18px;font-size:14px;font-weight:700;text-align:right;color:#1a365d;border-bottom:1px solid #f3f4f6;">{{ $recetaNombre }}</td>
              </tr>
              <tr style="background:#fafafa;">
                <td style="padding:12px 18px;font-size:13px;color:#6b7280;border-bottom:1px solid #f3f4f6;">Categoría</td>
                <td style="padding:12px 18px;font-size:13px;font-weight:600;text-align:right;color:#111827;border-bottom:1px solid #f3f4f6;">{{ $recetaCategoria ?: '—' }}</td>
              </tr>
              <tr style="background:#ffffff;">
                <td style="padding:12px 18px;font-size:13px;color:#6b7280;border-bottom:1px solid #f3f4f6;">Estado</td>
                <td style="padding:12px 18px;border-bottom:1px solid #f3f4f6;text-align:right;">
                  <span style="background:#c6f6d5;color:#276749;font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px;">Autorizada</span>
                </td>
              </tr>
              <tr style="background:#fafafa;">
                <td style="padding:12px 18px;font-size:13px;color:#6b7280;">Autorizado por</td>
                <td style="padding:12px 18px;font-size:13px;font-weight:600;text-align:right;color:#111827;">{{ $autorizadoPor }}</td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Acción requerida --}}
        <tr>
          <td style="padding:0 40px 24px;">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:#f0fff4;border-left:4px solid #276749;border-radius:4px;padding:14px 18px;">
                  <p style="margin:0 0 6px;color:#276749;font-size:13px;font-weight:700;">Acción requerida por IT</p>
                  <p style="margin:0;color:#2f855a;font-size:13px;line-height:1.6;">
                    📎 Se adjunta el PDF con la receta actualizada. Por favor actualizar la receta en el sistema correspondiente.
                  </p>
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
                <td style="background:#276749;border-radius:8px;padding:12px 32px;">
                  <a href="https://gestion-operaciones.cervezacadejo.com/recetas" style="color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;display:inline-block;letter-spacing:0.3px;">Ver catálogo de recetas</a>
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
