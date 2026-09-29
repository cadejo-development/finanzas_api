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
      <table width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.12);">

        {{-- Header --}}
        <tr>
          <td style="background:#1a1a1a;padding:32px 48px;text-align:center;">
            <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png" alt="Cadejo" width="80" style="display:block;margin:0 auto 16px;border-radius:50%;" />
            <p style="margin:0 0 6px 0;color:#f59e0b;font-size:11px;letter-spacing:3px;text-transform:uppercase;font-weight:600;">Cadejo Brewing Company</p>
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:1px;">Gestión de Operaciones</h1>
          </td>
        </tr>

        {{-- Banner --}}
        <tr>
          <td style="background:#b45309;padding:14px 48px;text-align:center;">
            <p style="margin:0;color:#ffffff;font-size:14px;font-weight:600;letter-spacing:1px;text-transform:uppercase;">⚠️ Revisión de Inventario Requerida</p>
          </td>
        </tr>

        {{-- Saludo --}}
        <tr>
          <td style="padding:32px 40px 20px;">
            <p style="margin:0 0 10px;color:#333333;font-size:16px;line-height:1.6;">
              Hola, <strong>{{ $destinatarioNombre }}</strong>
            </p>
            <p style="margin:0;color:#555555;font-size:15px;line-height:1.65;">
              El gerente de <strong>{{ $sucursalNombre }}</strong> ha identificado
              <strong>{{ count($items) }} {{ count($items) === 1 ? 'diferencia' : 'diferencias' }}</strong>
              en el conteo mensual del <strong>{{ \Carbon\Carbon::parse($fechaConteo)->format('d/m/Y') }}</strong>
              que requieren tu revisión:
            </p>
          </td>
        </tr>

        {{-- Tabla de productos --}}
        <tr>
          <td style="padding:0 40px 24px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;font-size:13px;">
              <tr style="background:#f3f4f6;">
                <th style="padding:10px 14px;text-align:left;color:#6b7280;font-weight:600;border-bottom:1px solid #e5e7eb;">Producto</th>
                <th style="padding:10px 14px;text-align:right;color:#6b7280;font-weight:600;border-bottom:1px solid #e5e7eb;">Diferencia</th>
                <th style="padding:10px 14px;text-align:right;color:#6b7280;font-weight:600;border-bottom:1px solid #e5e7eb;">Costo</th>
                <th style="padding:10px 14px;text-align:left;color:#6b7280;font-weight:600;border-bottom:1px solid #e5e7eb;">Tipo</th>
              </tr>
              @foreach($items as $i => $item)
              @php
                $diff   = $item['diferencia'] ?? 0;
                $signo  = $diff >= 0 ? '+' : '';
                $color  = $diff < 0 ? '#b91c1c' : ($diff > 0 ? '#1d4ed8' : '#374151');
                $bgRow  = $i % 2 === 0 ? '#ffffff' : '#fafafa';
              @endphp
              <tr style="background:{{ $bgRow }};">
                <td style="padding:10px 14px;color:#111827;border-bottom:1px solid #f3f4f6;">
                  <span style="font-weight:600;">{{ $item['nombre'] }}</span>
                  @if(!empty($item['codigo']))
                    <br><span style="color:#9ca3af;font-size:11px;font-family:monospace;">{{ $item['codigo'] }}</span>
                  @endif
                  @if(!empty($item['obs']))
                    <br><span style="color:#6b7280;font-style:italic;font-size:12px;">{{ $item['obs'] }}</span>
                  @endif
                </td>
                <td style="padding:10px 14px;text-align:right;font-weight:700;color:{{ $color }};border-bottom:1px solid #f3f4f6;white-space:nowrap;">
                  {{ $signo }}{{ number_format($diff, 2) }} {{ $item['unidad'] ?? '' }}
                  @if($item['dif_pct'] !== null)
                    <br><span style="font-size:11px;font-weight:400;color:#6b7280;">{{ number_format($item['dif_pct'], 1) }}%</span>
                  @endif
                </td>
                <td style="padding:10px 14px;text-align:right;color:#374151;border-bottom:1px solid #f3f4f6;white-space:nowrap;">
                  ${{ number_format(abs($item['costo_diff'] ?? 0), 2) }}
                </td>
                <td style="padding:10px 14px;color:#374151;border-bottom:1px solid #f3f4f6;">
                  <span style="background:#fff8ec;color:#92400e;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">{{ $item['just_label'] ?? '—' }}</span>
                </td>
              </tr>
              @endforeach
              {{-- Total --}}
              @php $totalCosto = collect($items)->sum(fn($i) => abs($i['costo_diff'] ?? 0)); @endphp
              <tr style="background:#f9fafb;">
                <td colspan="2" style="padding:10px 14px;font-size:12px;color:#6b7280;font-weight:600;">Total impacto económico</td>
                <td style="padding:10px 14px;text-align:right;font-weight:700;color:#111827;">${{ number_format($totalCosto, 2) }}</td>
                <td></td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Acción requerida --}}
        <tr>
          <td style="padding:0 40px 28px;">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:#fff8ec;border-left:4px solid #f59e0b;border-radius:4px;padding:14px 18px;">
                  <p style="margin:0 0 4px;color:#92400e;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Acción requerida</p>
                  <p style="margin:0;color:#7a5000;font-size:13px;line-height:1.6;">
                    Revise cada uno de los productos listados y tome las acciones correctivas correspondientes.
                    Responda este correo confirmando las correcciones realizadas.
                  </p>
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
              Este correo fue generado automáticamente por el Sistema de Inventario.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
