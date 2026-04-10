<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Código de recuperación</title>
  <style>
    body { margin:0; padding:0; background:#0c0a09; font-family:'Segoe UI',Arial,sans-serif; }
    .wrap { max-width:480px; margin:40px auto; background:#1c1917; border-radius:16px; overflow:hidden; border:1px solid #292524; }
    .header { background:linear-gradient(135deg,#1c1917 0%,#292524 100%); padding:32px 32px 24px; text-align:center; border-bottom:1px solid #292524; }
    .brand { color:#f59e0b; font-size:18px; font-weight:700; letter-spacing:0.5px; margin:12px 0 0; }
    .subtitle { color:#78716c; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin:4px 0 0; }
    .body { padding:32px; }
    .greeting { color:#d6d3d1; font-size:15px; margin:0 0 16px; }
    .msg { color:#a8a29e; font-size:14px; line-height:1.6; margin:0 0 28px; }
    .code-wrap { background:#0c0a09; border:1.5px solid #f59e0b40; border-radius:12px; padding:20px; text-align:center; margin:0 0 28px; }
    .code-label { color:#78716c; font-size:11px; text-transform:uppercase; letter-spacing:1.5px; margin:0 0 10px; }
    .code { color:#f59e0b; font-size:42px; font-weight:800; letter-spacing:12px; font-family:'Courier New',monospace; margin:0; }
    .expiry { color:#78716c; font-size:12px; margin:10px 0 0; }
    .warning { background:#1c0a09; border:1px solid #7f1d1d50; border-radius:8px; padding:14px 16px; margin:0 0 24px; }
    .warning p { color:#fca5a5; font-size:12px; margin:0; line-height:1.5; }
    .footer { padding:20px 32px 28px; text-align:center; border-top:1px solid #292524; }
    .footer p { color:#57534e; font-size:11px; margin:4px 0; line-height:1.5; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png"
           alt="Cadejo Brewing Company" width="72" height="72"
           style="border-radius:50%;border:1.5px solid rgba(245,158,11,0.3);background:rgba(245,158,11,0.12);display:block;margin:0 auto;" />
      <p class="brand">Cadejo Brewing Company</p>
      <p class="subtitle">Portal de Sistemas</p>
    </div>
    <div class="body">
      <p class="greeting">Hola, <strong style="color:#e7e5e4">{{ $userName }}</strong></p>
      <p class="msg">
        Recibimos una solicitud para recuperar la contraseña de tu cuenta.
        Usa el siguiente código para continuar con el proceso:
      </p>
      <div class="code-wrap">
        <p class="code-label">Código de verificación</p>
        <p class="code">{{ $code }}</p>
        <p class="expiry">⏱ Válido por <strong style="color:#f59e0b">15 minutos</strong></p>
      </div>
      <div class="warning">
        <p>⚠️ Si no solicitaste este código, ignora este correo. Nadie más tiene acceso a tu cuenta. <strong>Nunca compartas este código.</strong></p>
      </div>
    </div>
    <div class="footer">
      <p>Este correo fue generado automáticamente por el Portal de Sistemas.</p>
      <p>© {{ date('Y') }} Cadejo Brewing Company — Todos los derechos reservados.</p>
    </div>
  </div>
</body>
</html>
