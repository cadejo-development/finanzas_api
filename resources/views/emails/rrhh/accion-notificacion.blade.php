<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Notificacion de accion de personal</title>
</head>
<body style="margin:0;padding:0;background:#f5f0e8;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
@php
use Carbon\Carbon;
$dias  = ['domingo','lunes','martes','miercoles','jueves','viernes','sabado'];
$meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$formatearFecha = function($valor) use ($dias, $meses) {
    try {
        $c = Carbon::parse($valor);
        return $dias[$c->dayOfWeek] . ', ' . $c->day . ' de ' . $meses[$c->month - 1] . ' de ' . $c->year;
    } catch (\Throwable $e) { return $valor; }
};
$esFecha = function($label) {
    $lower = mb_strtolower($label);
    return str_contains($lower, 'fecha') || str_contains($lower, 'dia') || str_contains($lower, 'inicio') || str_contains($lower, 'fin') || str_contains($lower, 'desde') || str_contains($lower, 'hasta');
};
@endphp

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f0e8;padding:32px 16px;">
  <tr>
    <td align="center">
      <table width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.12);">

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
            <p style="margin:0;color:#ffffff;font-size:14px;font-weight:600;letter-spacing:1px;text-transform:uppercase;">Nuevo Registro</p>
          </td>
        </tr>

        {{-- Cuerpo --}}
        <tr>
          <td style="padding:32px 40px 10px;">
            <p style="margin:0 0 14px;color:#333333;font-size:16px;line-height:1.6;">
              Hola, <strong>{{ $supervisorNombre }}</strong>
            </p>
            <p style="margin:0 0 22px;color:#555555;font-size:15px;line-height:1.65;">
              Se ha registrado una nueva <strong>{{ strtolower($tipo) }}</strong> para el siguiente colaborador a tu cargo:
            </p>
            <p style="margin:0 0 20px;">
              <span style="display:inline-block;background:#fef3c7;color:#92400e;font-size:12px;font-weight:700;letter-spacing:0.5px;padding:4px 14px;border-radius:20px;border:1px solid #fcd34d;">{{ strtoupper($tipo) }}</span>
            </p>
          </td>
        </tr>

        {{-- Employee card --}}
        <tr>
          <td style="padding:0 40px 10px;">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;">
                  <p style="margin:0 0 2px;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:1px;">Colaborador</p>
                  <p style="margin:0;color:#111827;font-size:15px;font-weight:700;">{{ $empleadoNombre }}</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Detalles --}}
        @if(count($detalles))
        <tr>
          <td style="padding:10px 40px 10px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:8px;">
              @foreach(array_filter($detalles) as $label => $valor)
              <tr style="background:{{ $loop->odd ? '#fafafa' : '#ffffff' }};">
                <td style="padding:12px 18px;font-size:13px;width:42%;color:#6b7280;border-bottom:1px solid #f3f4f6;">{{ $label }}</td>
                <td style="padding:12px 18px;font-size:13px;font-weight:600;text-align:right;color:#111827;border-bottom:1px solid #f3f4f6;">{{ $esFecha($label) ? $formatearFecha($valor) : $valor }}</td>
              </tr>
              @endforeach
            </table>
          </td>
        </tr>
        @endif

        {{-- CTA --}}
        <tr>
          <td style="padding:18px 40px 36px;text-align:center;">
            <table cellpadding="0" cellspacing="0" align="center">
              <tr>
                <td style="background:#f59e0b;border-radius:8px;padding:12px 32px;">
                  <a href="{{ $linkUrl }}" style="color:#1a1a1a;font-size:14px;font-weight:700;text-decoration:none;display:inline-block;letter-spacing:0.3px;">Ver en el sistema</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- Aviso informativo --}}
        <tr>
          <td style="padding:0 40px 28px;">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:#fff8ec;border-left:4px solid #f59e0b;border-radius:4px;padding:14px 18px;">
                  <p style="margin:0;color:#7a5000;font-size:13px;line-height:1.6;">
                    Este es un correo informativo. No se requiere ninguna accion de tu parte.
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
            <p style="margin:0;color:#6b7280;font-size:11px;">Este correo fue generado automaticamente por el modulo de Gestion de Talento.</p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
