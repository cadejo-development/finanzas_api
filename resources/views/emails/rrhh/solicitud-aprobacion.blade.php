<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Solicitud de aprobación</title>
  <style>
    body { margin:0; padding:0; background:#0c0a09; font-family:'Segoe UI',Arial,sans-serif; }
    .wrap { max-width:520px; margin:40px auto; background:#1c1917; border-radius:16px; overflow:hidden; border:1px solid #292524; }
    .header { background:linear-gradient(135deg,#1c1917 0%,#292524 100%); padding:48px 32px 32px; text-align:center; border-bottom:1px solid #292524; }
    .brand { color:#f59e0b; font-size:18px; font-weight:700; letter-spacing:0.5px; margin:16px 0 0; }
    .subtitle { color:#78716c; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin:5px 0 0; }
    .body { padding:28px 32px; }
    .greeting { color:#d6d3d1; font-size:15px; margin:0 0 14px; }
    .msg { color:#a8a29e; font-size:14px; line-height:1.6; margin:0 0 20px; }
    .tipo-badge { display:inline-block; background:#f59e0b1a; border:1px solid #f59e0b40; color:#f59e0b; border-radius:8px; padding:4px 14px; font-size:13px; font-weight:700; margin-bottom:20px; letter-spacing:0.5px; }
    .employee-card { background:#292524; border:1px solid #44403c; border-radius:12px; padding:16px 20px; margin:0 0 20px; display:flex; align-items:center; gap:14px; }
    .avatar { width:44px; height:44px; border-radius:50%; background:#f59e0b1a; border:1.5px solid #f59e0b40; display:flex; align-items:center; justify-content:center; color:#f59e0b; font-size:18px; font-weight:800; flex-shrink:0; }
    .emp-name { color:#e7e5e4; font-size:15px; font-weight:700; margin:0 0 2px; }
    .emp-sub { color:#78716c; font-size:12px; margin:0; }
    .details-table { width:100%; border-collapse:collapse; margin:0 0 24px; }
    .details-table tr { border-bottom:1px solid #292524; }
    .details-table tr:last-child { border-bottom:none; }
    .details-table td { padding:9px 4px; font-size:13px; }
    .details-table td:first-child { color:#78716c; width:40%; }
    .details-table td:last-child { color:#d6d3d1; font-weight:600; text-align:right; }
    .actions { display:table; width:100%; border-collapse:separate; border-spacing:10px 0; margin:0 0 8px; }
    .actions-row { display:table-row; }
    .actions-cell { display:table-cell; width:50%; }
    .btn-aprobar  { display:block; background:#16a34a; color:#fff; text-decoration:none; border-radius:10px; padding:14px 12px; font-size:14px; font-weight:800; letter-spacing:0.3px; text-align:center; }
    .btn-rechazar { display:block; background:#292524; color:#ef4444; text-decoration:none; border-radius:10px; padding:13px 12px; font-size:14px; font-weight:800; letter-spacing:0.3px; text-align:center; border:1.5px solid #ef444440; }
    .cta-wrap { text-align:center; margin:16px 0 8px; }
    .cta-link { color:#78716c; font-size:12px; text-decoration:underline; }
    .note { color:#57534e; font-size:12px; text-align:center; margin:12px 0 0; line-height:1.5; }
    .footer { padding:18px 32px 24px; text-align:center; border-top:1px solid #292524; }
    .footer p { color:#57534e; font-size:11px; margin:3px 0; line-height:1.5; }
  </style>
</head>
<body>
@php
use Carbon\Carbon;

$dias   = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$meses  = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

$formatearFecha = function($valor) use ($dias, $meses) {
    try {
        $c = Carbon::parse($valor);
        return $dias[$c->dayOfWeek] . ', ' . $c->day . ' de ' . $meses[$c->month - 1] . ' de ' . $c->year;
    } catch (\Throwable $e) {
        return $valor;
    }
};

$esFecha = function($label) {
    $lower = mb_strtolower($label);
    return str_contains($lower, 'fecha') || str_contains($lower, 'día') || str_contains($lower, 'inicio') || str_contains($lower, 'fin');
};
@endphp

  <div class="wrap">
    <div class="header">
      <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png"
           alt="Cadejo Brewing Company" width="88" height="88"
           style="border-radius:50%;border:2px solid rgba(245,158,11,0.35);background:rgba(245,158,11,0.1);display:block;margin:0 auto;padding:4px;" />
      <p class="brand">Cadejo Brewing Company</p>
      <p class="subtitle">Gestión de Talento</p>
    </div>

    <div class="body">
      <p class="greeting">Hola, <strong style="color:#e7e5e4">{{ $supervisorNombre }}</strong></p>

      <p class="msg">
        Tienes una nueva solicitud de <strong style="color:#f59e0b">{{ $tipo }}</strong>
        pendiente de revisión y aprobación:
      </p>

      <span class="tipo-badge">{{ strtoupper($tipo) }}</span>

      <div class="employee-card">
        <div class="avatar">{{ mb_strtoupper(mb_substr($empleadoNombre, 0, 1)) }}</div>
        <div>
          <p class="emp-name">{{ $empleadoNombre }}</p>
          <p class="emp-sub">Ha solicitado este {{ strtolower($tipo) }}</p>
        </div>
      </div>

      @if(count($detalles))
      <table class="details-table">
        @foreach(array_filter($detalles) as $label => $valor)
        <tr>
          <td>{{ $label }}</td>
          <td>{{ $esFecha($label) ? $formatearFecha($valor) : $valor }}</td>
        </tr>
        @endforeach
      </table>
      @endif

      @if($aprobarUrl && $rechazarUrl)
      <table class="actions">
        <tr class="actions-row">
          <td class="actions-cell">
            <a href="{{ $aprobarUrl }}" class="btn-aprobar">✓ Aprobar</a>
          </td>
          <td class="actions-cell">
            <a href="{{ $rechazarUrl }}" class="btn-rechazar">✗ Rechazar</a>
          </td>
        </tr>
      </table>
      <div class="cta-wrap">
        <a href="{{ $linkUrl }}" class="cta-link">Ver detalles en el sistema</a>
      </div>
      @else
      <div class="cta-wrap">
        <a href="{{ $linkUrl }}" style="display:inline-block;background:#f59e0b;color:#1c1917;text-decoration:none;border-radius:10px;padding:13px 32px;font-size:14px;font-weight:800;letter-spacing:0.5px;">Revisar solicitud</a>
      </div>
      @endif

      <p class="note">Los botones de esta acción son válidos por 5 días.</p>
    </div>

    <div class="footer">
      <p>Este correo fue generado automáticamente por el módulo de Gestión de Talento.</p>
      <p>© {{ date('Y') }} Cadejo Brewing Company — Todos los derechos reservados.</p>
    </div>
  </div>
</body>
</html>
