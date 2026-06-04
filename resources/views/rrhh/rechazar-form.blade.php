<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Rechazar Amonestación</title>
  <style>
    body { margin:0; padding:0; background:#0c0a09; font-family:'Segoe UI',Arial,sans-serif; min-height:100vh; display:flex; align-items:center; justify-content:center; }
    .card { max-width:480px; width:90%; background:#1c1917; border-radius:16px; border:1px solid #292524; overflow:hidden; }
    .card-header { padding:32px 32px 24px; border-bottom:1px solid #292524; background:linear-gradient(135deg,#1c1917 0%,#292524 100%); text-align:center; }
    .card-header img { border-radius:50%; border:1.5px solid rgba(245,158,11,0.3); background:rgba(245,158,11,0.12); display:block; margin:0 auto 14px; }
    .card-header h1 { color:#e7e5e4; font-size:18px; font-weight:700; margin:0 0 6px; }
    .card-header p  { color:#78716c; font-size:13px; margin:0; }
    .card-body { padding:28px 32px 36px; }
    .tipo-badge { display:inline-block; background:#ef44441a; border:1px solid #ef444440; color:#fca5a5; border-radius:8px; padding:4px 16px; font-size:12px; font-weight:700; letter-spacing:0.5px; text-transform:uppercase; margin-bottom:20px; }
    .info-block { background:#292524; border-radius:10px; padding:14px 16px; margin-bottom:20px; font-size:13px; color:#a8a29e; }
    .info-block strong { color:#e7e5e4; display:block; margin-bottom:4px; }
    label { display:block; color:#d6d3d1; font-size:13px; font-weight:600; margin-bottom:8px; }
    textarea { width:100%; background:#0c0a09; border:1px solid #44403c; border-radius:10px; color:#e7e5e4; font-size:14px; font-family:inherit; padding:12px 14px; resize:vertical; min-height:100px; box-sizing:border-box; outline:none; }
    textarea:focus { border-color:#f59e0b; }
    .btn-rechazar { display:block; width:100%; background:#dc2626; color:#fff; border:none; border-radius:10px; padding:13px; font-size:14px; font-weight:700; cursor:pointer; margin-top:18px; letter-spacing:0.3px; }
    .btn-rechazar:hover { background:#b91c1c; }
    .footer { color:#57534e; font-size:11px; margin-top:20px; line-height:1.5; text-align:center; }
  </style>
</head>
<body>
  <div class="card">
    <div class="card-header">
      <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png"
           alt="Cadejo" width="52" height="52" />
      <h1>Rechazar Amonestación Muy Grave</h1>
      <p>Gestión de Talento — Cadejo Brewing Company</p>
    </div>

    <div class="card-body">
      <span class="tipo-badge">Amonestación Muy Grave</span>

      <div class="info-block">
        <strong>Empleado afectado</strong>
        Amonestación #{{ $amonestacion->id }} — {{ $amonestacion->fecha_amonestacion }}
      </div>

      <form method="POST" action="{{ route('rrhh.email.rechazar.motivo', ['tipo' => $tipo, 'id' => $id]) }}">
        @csrf
        <label for="motivo">Motivo del rechazo <span style="color:#78716c;font-weight:400;">(opcional)</span></label>
        <textarea id="motivo" name="motivo" placeholder="Describe el motivo por el cual se rechaza esta amonestación..."></textarea>
        <button type="submit" class="btn-rechazar">Confirmar rechazo</button>
      </form>

      <p class="footer">
        © {{ date('Y') }} Cadejo Brewing Company<br>
        Este correo fue generado automáticamente por el módulo de Gestión de Talento.
      </p>
    </div>
  </div>
</body>
</html>
