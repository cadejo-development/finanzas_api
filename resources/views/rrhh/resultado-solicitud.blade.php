<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Resultado de solicitud</title>
  <style>
    body { margin:0; padding:0; background:#0c0a09; font-family:'Segoe UI',Arial,sans-serif; min-height:100vh; display:flex; align-items:center; justify-content:center; }
    .card { max-width:440px; width:90%; background:#1c1917; border-radius:16px; border:1px solid #292524; overflow:hidden; text-align:center; }
    .card-header { padding:40px 32px 28px; border-bottom:1px solid #292524; background:linear-gradient(135deg,#1c1917 0%,#292524 100%); }
    .icon { width:64px; height:64px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:28px; margin:0 auto 16px; }
    .icon-ok   { background:rgba(34,197,94,.12); border:1.5px solid rgba(34,197,94,.3); }
    .icon-fail { background:rgba(239,68,68,.12);  border:1.5px solid rgba(239,68,68,.3); }
    .card-header h1 { color:#e7e5e4; font-size:20px; font-weight:700; margin:0 0 6px; }
    .card-header p  { color:#78716c; font-size:13px; margin:0; }
    .card-body { padding:28px 32px 36px; }
    .msg { font-size:15px; line-height:1.6; margin:0 0 24px; }
    .msg-ok   { color:#86efac; }
    .msg-fail { color:#fca5a5; }
    .tipo-badge { display:inline-block; background:#f59e0b1a; border:1px solid #f59e0b40; color:#f59e0b; border-radius:8px; padding:4px 16px; font-size:12px; font-weight:700; letter-spacing:0.5px; text-transform:uppercase; margin-bottom:20px; }
    .btn { display:inline-block; background:#f59e0b; color:#1c1917; text-decoration:none; border-radius:10px; padding:12px 28px; font-size:14px; font-weight:800; letter-spacing:0.3px; }
    .footer { color:#57534e; font-size:11px; margin-top:20px; line-height:1.5; }
  </style>
</head>
<body>
  <div class="card">
    <div class="card-header">
      <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png"
           alt="Cadejo Brewing Company" width="56" height="56"
           style="border-radius:50%;border:1.5px solid rgba(245,158,11,0.3);background:rgba(245,158,11,0.12);display:block;margin:0 auto 16px;" />

      <div class="icon {{ $exito ? 'icon-ok' : 'icon-fail' }}">
        @if($exito)
          {{ $accion === 'aprobado' ? '✓' : '✗' }}
        @else
          ⚠
        @endif
      </div>

      <h1>
        @if($exito)
          Solicitud {{ $accion === 'aprobado' ? 'aprobada' : 'rechazada' }}
        @else
          No se pudo procesar
        @endif
      </h1>
      <p>Gestión de Talento — Cadejo Brewing Company</p>
    </div>

    <div class="card-body">
      <span class="tipo-badge">{{ strtoupper($tipo) }}</span>

      <p class="msg {{ $exito ? 'msg-ok' : 'msg-fail' }}">{{ $mensaje }}</p>

      <a href="https://talentohumano.cervezacadejo.com" class="btn">Ir al sistema</a>

      <p class="footer">
        © {{ date('Y') }} Cadejo Brewing Company<br>
        Este correo fue generado automáticamente por el módulo de Gestión de Talento.
      </p>
    </div>
  </div>
</body>
</html>
