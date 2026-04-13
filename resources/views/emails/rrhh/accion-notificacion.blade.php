<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Notificación de acción personal</title>
  <style>
    body { margin:0; padding:0; background:#0c0a09; font-family:'Segoe UI',Arial,sans-serif; }
    .wrap { max-width:520px; margin:40px auto; background:#1c1917; border-radius:16px; overflow:hidden; border:1px solid #292524; }
    .header { background:linear-gradient(135deg,#1c1917 0%,#292524 100%); padding:32px 32px 24px; text-align:center; border-bottom:1px solid #292524; }
    .brand { color:#f59e0b; font-size:18px; font-weight:700; letter-spacing:0.5px; margin:12px 0 0; }
    .subtitle { color:#78716c; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin:4px 0 0; }
    .body { padding:28px 32px; }
    .greeting { color:#d6d3d1; font-size:15px; margin:0 0 14px; }
    .msg { color:#a8a29e; font-size:14px; line-height:1.6; margin:0 0 20px; }
    .tipo-badge { display:inline-block; background:#3b82f61a; border:1px solid #3b82f640; color:#60a5fa; border-radius:8px; padding:4px 14px; font-size:13px; font-weight:700; margin-bottom:20px; letter-spacing:0.5px; }
    .employee-card { background:#292524; border:1px solid #44403c; border-radius:12px; padding:16px 20px; margin:0 0 20px; display:flex; align-items:center; gap:14px; }
    .avatar { width:44px; height:44px; border-radius:50%; background:#3b82f61a; border:1.5px solid #3b82f640; display:flex; align-items:center; justify-content:center; color:#60a5fa; font-size:18px; font-weight:800; flex-shrink:0; }
    .emp-name { color:#e7e5e4; font-size:15px; font-weight:700; margin:0 0 2px; }
    .emp-sub { color:#78716c; font-size:12px; margin:0; }
    .details-table { width:100%; border-collapse:collapse; margin:0 0 24px; }
    .details-table tr { border-bottom:1px solid #292524; }
    .details-table tr:last-child { border-bottom:none; }
    .details-table td { padding:9px 4px; font-size:13px; }
    .details-table td:first-child { color:#78716c; width:40%; }
    .details-table td:last-child { color:#d6d3d1; font-weight:600; text-align:right; }
    .cta-wrap { text-align:center; margin:20px 0 8px; }
    .cta-btn { display:inline-block; background:#292524; color:#d6d3d1; text-decoration:none; border-radius:10px; padding:12px 28px; font-size:13px; font-weight:700; border:1px solid #44403c; }
    .note { color:#57534e; font-size:12px; text-align:center; margin:10px 0 0; line-height:1.5; }
    .footer { padding:18px 32px 24px; text-align:center; border-top:1px solid #292524; }
    .footer p { color:#57534e; font-size:11px; margin:3px 0; line-height:1.5; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png"
           alt="Cadejo Brewing Company" width="72" height="72"
           style="border-radius:50%;border:1.5px solid rgba(245,158,11,0.3);background:rgba(245,158,11,0.12);display:block;margin:0 auto;" />
      <p class="brand">Cadejo Brewing Company</p>
      <p class="subtitle">Gestión de Talento</p>
    </div>

    <div class="body">
      <p class="greeting">Hola, <strong style="color:#e7e5e4">{{ $supervisorNombre }}</strong></p>

      <p class="msg">
        Se ha registrado una nueva <strong style="color:#60a5fa">{{ strtolower($tipo) }}</strong>
        para el siguiente colaborador a tu cargo:
      </p>

      <span class="tipo-badge">{{ strtoupper($tipo) }}</span>

      <div class="employee-card">
        <div class="avatar">{{ mb_strtoupper(mb_substr($empleadoNombre, 0, 1)) }}</div>
        <div>
          <p class="emp-name">{{ $empleadoNombre }}</p>
          <p class="emp-sub">Registro en Gestión de Talento</p>
        </div>
      </div>

      @if(count($detalles))
      <table class="details-table">
        @foreach(array_filter($detalles) as $label => $valor)
        <tr>
          <td>{{ $label }}</td>
          <td>{{ $valor }}</td>
        </tr>
        @endforeach
      </table>
      @endif

      <div class="cta-wrap">
        <a href="{{ $linkUrl }}" class="cta-btn">Ver en el sistema</a>
      </div>
      <p class="note">Este es un correo informativo. No se requiere ninguna acción.</p>
    </div>

    <div class="footer">
      <p>Este correo fue generado automáticamente por el módulo de Gestión de Talento.</p>
      <p>© {{ date('Y') }} Cadejo Brewing Company — Todos los derechos reservados.</p>
    </div>
  </div>
</body>
</html>
