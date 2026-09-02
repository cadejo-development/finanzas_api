<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Revisión de inventario requerida</title>
</head>
<body style="margin:0;padding:0;background:#f5f0e8;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f0e8;padding:32px 16px;">
  <tr>
    <td align="center">
      <table width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.12);">

        {{-- Header --}}
        <tr>
          <td style="background:#1a1a1a;padding:32px 48px;text-align:center;">
            <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png" alt="Cadejo" width="80" style="display:block;margin:0 auto 16px;border-radius:50%;" />
            <p style="margin:0 0 6px 0;color:#f59e0b;font-size:11px;letter-spacing:3px;text-transform:uppercase;font-weight:600;">Cadejo Brewing Company</p>
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:1px;">🍽️ Sistema de Inventario</h1>
          </td>
        </tr>

        {{-- Banner --}}
        @php
          $accionBanner = match($tipoResponsabilidad) {
            'error_receta'         => 'Error en Receta',
            'error_posteo'         => 'Error en Posteo',
            'error_traslado'       => 'Error en Traslado',
            'codigo_mp_equivocado' => 'Código MP Equivocado',
            default                => 'Revisión Requerida',
          };
          $instruccion = match($tipoResponsabilidad) {
            'error_receta'         => 'Revise la receta indicada. Si hay un error, corrígalo en el sistema y responda este correo confirmando el cambio realizado.',
            'error_posteo'         => 'Revise el posteo o compra correspondiente. Si hay un error en el ingreso, corrígalo y responda este correo confirmando la corrección.',
            'error_traslado'       => 'Revise el traslado entre sucursales. Si hay un error, corrígalo y responda confirmando la acción tomada.',
            'codigo_mp_equivocado' => 'Revise el código de materia prima utilizado en la receta o compra. Corrija el código incorrecto y responda confirmando el cambio.',
            default                => 'Revise el caso indicado y tome las acciones correctivas correspondientes.',
          };
          $diff = $item['diferencia'] ?? 0;
          $signo = $diff >= 0 ? '+' : '';
          $colorDiff = $diff < 0 ? '#b91c1c' : ($diff > 0 ? '#1d4ed8' : '#374151');
        @endphp
        <tr>
          <td style="background:#b45309;padding:14px 48px;text-align:center;">
            <p style="margin:0;color:#ffffff;font-size:14px;font-weight:600;letter-spacing:1px;text-transform:uppercase;">⚠️ {{ $accionBanner }}</p>
          </td>
        </tr>

        {{-- Saludo --}}
        <tr>
          <td style="padding:32px 40px 16px;">
            <p style="margin:0 0 14px;color:#333333;font-size:16px;line-height:1.6;">
              Hola, <strong>{{ $destinatarioNombre }}</strong>
            </p>
            <p style="margin:0 0 4px;color:#555555;font-size:15px;line-height:1.65;">
              El gerente de <strong>{{ $sucursalNombre }}</strong> ha identificado una diferencia en el conteo mensual
              del <strong>{{ \Carbon\Carbon::parse($fechaConteo)->format('d/m/Y') }}</strong>
              y la ha asignado a tu revisión.
            </p>
          </td>
        </tr>

        {{-- Card del producto --}}
        <tr>
          <td style="padding:0 40px 10px;">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;">
                  @if(!empty($item['codigo']))
                    <p style="margin:0 0 2px;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:1px;">Código: <span style="font-family:monospace;">{{ $item['codigo'] }}</span></p>
                  @endif
                  <p style="margin:0;color:#111827;font-size:16px;font-weight:700;">{{ $item['nombre'] }}</p>
                  @if(!empty($item['unidad']))
                    <p style="margin:4px 0 0;color:#6b7280;font-size:12px;">Unidad: {{ $item['unidad'] }}</p>
                  @endif
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Detalles numéricos --}}
        <tr>
          <td style="padding:10px 40px 10px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
              <tr style="background:#fafafa;">
                <td style="padding:12px 18px;font-size:13px;width:50%;color:#6b7280;border-bottom:1px solid #f3f4f6;">Diferencia</td>
                <td style="padding:12px 18px;font-size:14px;font-weight:700;text-align:right;color:{{ $colorDiff }};border-bottom:1px solid #f3f4f6;">{{ $signo }}{{ number_format($diff, 2) }} {{ $item['unidad'] ?? '' }}</td>
              </tr>
              <tr style="background:#ffffff;">
                <td style="padding:12px 18px;font-size:13px;color:#6b7280;border-bottom:1px solid #f3f4f6;">Diferencia %</td>
                <td style="padding:12px 18px;font-size:13px;font-weight:600;text-align:right;color:#374151;border-bottom:1px solid #f3f4f6;">
                  {{ $item['dif_pct'] !== null ? number_format($item['dif_pct'], 1).'%' : '—' }}
                </td>
              </tr>
              <tr style="background:#fafafa;">
                <td style="padding:12px 18px;font-size:13px;color:#6b7280;border-bottom:1px solid #f3f4f6;">Costo de la diferencia</td>
                <td style="padding:12px 18px;font-size:13px;font-weight:600;text-align:right;color:#374151;border-bottom:1px solid #f3f4f6;">
                  ${{ number_format(abs($item['costo_diff'] ?? 0), 2) }}
                </td>
              </tr>
              <tr style="background:#ffffff;">
                <td style="padding:12px 18px;font-size:13px;color:#6b7280;">Categoría</td>
                <td style="padding:12px 18px;font-size:13px;font-weight:600;text-align:right;color:#374151;">{{ $item['just_label'] ?? '—' }}</td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Acción requerida --}}
        <tr>
          <td style="padding:10px 40px 28px;">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:#fff8ec;border-left:4px solid #f59e0b;border-radius:4px;padding:14px 18px;">
                  <p style="margin:0 0 4px;color:#92400e;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Acción requerida</p>
                  <p style="margin:0;color:#7a5000;font-size:13px;line-height:1.6;">{{ $instruccion }}</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Footer --}}
        <tr>
          <td style="background:#1a1a1a;padding:24px 40px;text-align:center;">
            <p style="margin:0 0 4px;color:#f59e0b;font-size:12px;font-weight:600;">Cadejo Brewing Company</p>
            <p style="margin:0;color:#6b7280;font-size:11px;">
              Enviado por {{ $gerenteNombre }} &mdash; {{ now()->setTimezone('America/El_Salvador')->format('d/m/Y H:i') }}<br>
              Este correo fue generado automáticamente por el Sistema de Inventario. Responda directamente si tiene comentarios.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
