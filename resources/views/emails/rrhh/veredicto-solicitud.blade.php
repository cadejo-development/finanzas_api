<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Veredicto de solicitud</title>
  <style>
    body { margin:0; padding:0; background:#0c0a09; font-family:'Segoe UI',Arial,sans-serif; }
    .wrap { max-width:520px; margin:40px auto; background:#1c1917; border-radius:16px; overflow:hidden; border:1px solid #292524; }

    .header { padding:48px 32px 32px; text-align:center; border-bottom:1px solid #292524; }
    .header-aprobado { background:linear-gradient(135deg, #052e16 0%, #14532d 100%); }
    .header-rechazado { background:linear-gradient(135deg, #1c1917 0%, #292524 100%); }

    .brand   { font-size:18px; font-weight:700; letter-spacing:0.5px; margin:16px 0 0; }
    .brand-aprobado  { color:#86efac; }
    .brand-rechazado { color:#f59e0b; }
    .subtitle { color:#78716c; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin:5px 0 0; }

    .verdict-badge { display:inline-block; border-radius:50px; padding:8px 22px; font-size:13px; font-weight:800; letter-spacing:1px; text-transform:uppercase; margin-top:18px; }
    .badge-aprobado  { background:rgba(34,197,94,.15); border:1.5px solid rgba(34,197,94,.4); color:#86efac; }
    .badge-rechazado { background:rgba(239,68,68,.12); border:1.5px solid rgba(239,68,68,.35); color:#fca5a5; }

    .body { padding:28px 32px; }
    .greeting { color:#d6d3d1; font-size:15px; margin:0 0 14px; }
    .msg { color:#a8a29e; font-size:14px; line-height:1.6; margin:0 0 22px; }

    .tipo-badge { display:inline-block; background:#f59e0b1a; border:1px solid #f59e0b40; color:#f59e0b; border-radius:8px; padding:4px 14px; font-size:13px; font-weight:700; margin-bottom:20px; letter-spacing:0.5px; }

    .result-card { border-radius:12px; padding:18px 20px; margin:0 0 22px; }
    .card-aprobado  { background:#052e1680; border:1.5px solid rgba(34,197,94,.25); }
    .card-rechazado { background:#450a0a40; border:1.5px solid rgba(239,68,68,.2); }
    .result-icon { font-size:24px; margin:0 0 6px; }
    .result-title { font-size:15px; font-weight:700; margin:0 0 4px; }
    .title-aprobado  { color:#86efac; }
    .title-rechazado { color:#fca5a5; }
    .result-sub { color:#78716c; font-size:13px; margin:0; }

    .details-table { width:100%; border-collapse:collapse; margin:0 0 24px; }
    .details-table tr { border-bottom:1px solid #292524; }
    .details-table tr:last-child { border-bottom:none; }
    .details-table td { padding:9px 4px; font-size:13px; }
    .details-table td:first-child { color:#78716c; width:40%; }
    .details-table td:last-child { color:#d6d3d1; font-weight:600; text-align:right; }

    .reviewer { background:#292524; border:1px solid #44403c; border-radius:10px; padding:12px 16px; margin:0 0 24px; display:flex; align-items:center; gap:12px; }
    .reviewer-avatar { width:36px; height:36px; border-radius:50%; background:#f59e0b1a; border:1.5px solid #f59e0b40; display:flex; align-items:center; justify-content:center; color:#f59e0b; font-size:14px; font-weight:800; flex-shrink:0; }
    .reviewer-name  { color:#d6d3d1; font-size:13px; font-weight:700; margin:0 0 2px; }
    .reviewer-label { color:#78716c; font-size:11px; margin:0; }

    .cta-wrap { text-align:center; margin:8px 0 8px; }
    .cta-btn  { display:inline-block; background:#f59e0b; color:#1c1917; text-decoration:none; border-radius:10px; padding:13px 32px; font-size:14px; font-weight:800; letter-spacing:0.5px; }

    .footer { padding:18px 32px 24px; text-align:center; border-top:1px solid #292524; }
    .footer p { color:#57534e; font-size:11px; margin:3px 0; line-height:1.5; }
  </style>
</head>
<body>
@php
use Carbon\Carbon;

$dias  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

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
    return str_contains($lower, 'fecha') || str_contains($lower, 'inicio') || str_contains($lower, 'fin');
};

$aprobado = $estado === 'aprobado';
@endphp

  <div class="wrap">
    <div class="header {{ $aprobado ? 'header-aprobado' : 'header-rechazado' }}">
      <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png"
           alt="Cadejo Brewing Company" width="88" height="88"
           style="border-radius:50%;border:2px solid rgba(245,158,11,0.35);background:rgba(245,158,11,0.1);display:block;margin:0 auto;padding:4px;" />
      <p class="brand {{ $aprobado ? 'brand-aprobado' : 'brand-rechazado' }}">Cadejo Brewing Company</p>
      <p class="subtitle">Gestión de Talento</p>
      <span class="verdict-badge {{ $aprobado ? 'badge-aprobado' : 'badge-rechazado' }}">
        {{ $aprobado ? '✓ Aprobado' : '✗ Rechazado' }}
      </span>
    </div>

    <div class="body">
      <p class="greeting">Hola, <strong style="color:#e7e5e4">{{ $empleadoNombre }}</strong></p>

      <p class="msg">
        @if($aprobado)
          Tu solicitud de <strong style="color:#86efac">{{ $tipo }}</strong> ha sido
          <strong style="color:#86efac">aprobada</strong>. Puedes revisar el detalle a continuación.
        @else
          Tu solicitud de <strong style="color:#fca5a5">{{ $tipo }}</strong> ha sido
          <strong style="color:#fca5a5">rechazada</strong>. Puedes comunicarte con tu jefe para más información.
        @endif
      </p>

      <span class="tipo-badge">{{ strtoupper($tipo) }}</span>

      <div class="result-card {{ $aprobado ? 'card-aprobado' : 'card-rechazado' }}">
        <p class="result-icon">{{ $aprobado ? '✓' : '✗' }}</p>
        <p class="result-title {{ $aprobado ? 'title-aprobado' : 'title-rechazado' }}">
          Solicitud {{ $aprobado ? 'aprobada' : 'rechazada' }}
        </p>
        <p class="result-sub">Revisada por tu supervisor directo</p>
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

      <div class="reviewer">
        <div class="reviewer-avatar">{{ mb_strtoupper(mb_substr($supervisorNombre, 0, 1)) }}</div>
        <div>
          <p class="reviewer-name">{{ $supervisorNombre }}</p>
          <p class="reviewer-label">Supervisor — emitió el veredicto</p>
        </div>
      </div>

      <div class="cta-wrap">
        <a href="{{ $linkUrl }}" class="cta-btn">Ver mi solicitud</a>
      </div>
    </div>

    <div class="footer">
      <p>Este correo fue generado automáticamente por el módulo de Gestión de Talento.</p>
      <p>© {{ date('Y') }} Cadejo Brewing Company — Todos los derechos reservados.</p>
    </div>
  </div>
</body>
</html>
