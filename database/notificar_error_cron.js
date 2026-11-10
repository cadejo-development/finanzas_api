/**
 * notificar_error_cron.js
 * Envía un email de resultado (éxito o fallo) cuando termina un cron job.
 *
 * Uso: node database/notificar_error_cron.js --job=NOMBRE --exit=0 --log=PATH [--email=otro@correo.com]
 */
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const fs   = require('fs');
const path = require('path');
const { SESClient, SendEmailCommand } = require('@aws-sdk/client-ses');

const JOB      = process.argv.find(a => a.startsWith('--job='))?.split('=').slice(1).join('=') ?? 'desconocido';
const EXIT     = parseInt(process.argv.find(a => a.startsWith('--exit='))?.split('=')[1] ?? '0');
const LOG      = process.argv.find(a => a.startsWith('--log='))?.split('=').slice(1).join('=') ?? null;
const DEST     = process.argv.find(a => a.startsWith('--email='))?.split('=').slice(1).join('=')
              ?? process.env.CRON_ALERT_EMAIL
              ?? 'javiermejia@cervezacadejo.com';

const OK = EXIT === 0;

function readTail(filePath, lines = 60) {
  try {
    const content = fs.readFileSync(filePath, 'utf8');
    return content.split('\n').slice(-lines).join('\n');
  } catch {
    return '(no se pudo leer el log)';
  }
}

async function main() {
  const tail  = LOG ? readTail(LOG) : '(sin log)';
  const fecha = new Date().toLocaleString('es-SV', { timeZone: 'America/El_Salvador' });
  const yr    = new Date().getFullYear();

  const bannerBg    = OK ? '#16a34a' : '#dc2626';
  const bannerText  = OK ? '✓ Cron Job Exitoso' : '⚠ Cron Job Fallido';
  const rowBg       = OK ? '#f0fdf4' : '#fef2f2';
  const labelColor  = OK ? '#166534' : '#991b1b';
  const statusBadge = OK
    ? `<span style="background:#dcfce7;color:#166534;font-size:12px;font-weight:700;padding:3px 10px;border-radius:4px;">EXITOSO</span>`
    : `<span style="background:#fef2f2;color:#b91c1c;font-size:12px;font-weight:700;padding:3px 10px;border-radius:4px;">FALLÓ (exit ${EXIT})</span>`;

  const html = `<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"/></head>
<body style="margin:0;padding:0;background:#f5f0e8;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f0e8;padding:32px 16px;">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:660px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.12);">
  <tr>
    <td style="background:#1a1a1a;padding:24px 40px;text-align:center;">
      <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png" alt="Cadejo" width="56" style="display:block;margin:0 auto 10px;border-radius:50%;"/>
      <p style="margin:0;color:#f59e0b;font-size:11px;letter-spacing:3px;text-transform:uppercase;font-weight:600;">Cadejo Brewing Company</p>
    </td>
  </tr>
  <tr>
    <td style="background:${bannerBg};padding:14px 40px;text-align:center;">
      <p style="margin:0;color:#ffffff;font-size:14px;font-weight:700;letter-spacing:1px;text-transform:uppercase;">${bannerText}</p>
    </td>
  </tr>
  <tr>
    <td style="padding:28px 40px;">
      <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e7e5e4;border-radius:8px;overflow:hidden;margin-bottom:24px;">
        <tr style="background:${rowBg};">
          <td style="padding:12px 16px;font-size:12px;font-weight:700;color:${labelColor};text-transform:uppercase;letter-spacing:1px;width:110px;">Job</td>
          <td style="padding:12px 16px;font-size:14px;color:#292524;font-weight:600;">${JOB}</td>
        </tr>
        <tr>
          <td style="padding:12px 16px;font-size:12px;font-weight:700;color:#78716c;text-transform:uppercase;letter-spacing:1px;">Estado</td>
          <td style="padding:12px 16px;">${statusBadge}</td>
        </tr>
        <tr style="background:#fafaf9;">
          <td style="padding:12px 16px;font-size:12px;font-weight:700;color:#78716c;text-transform:uppercase;letter-spacing:1px;">Fecha</td>
          <td style="padding:12px 16px;font-size:13px;color:#44403c;">${fecha}</td>
        </tr>
        ${LOG ? `<tr>
          <td style="padding:12px 16px;font-size:12px;font-weight:700;color:#78716c;text-transform:uppercase;letter-spacing:1px;">Log</td>
          <td style="padding:12px 16px;font-size:12px;color:#78716c;font-family:monospace;">${path.basename(LOG)}</td>
        </tr>` : ''}
      </table>
      <p style="margin:0 0 10px;font-size:13px;font-weight:700;color:#1a1a1a;text-transform:uppercase;letter-spacing:1px;">Últimas líneas del log:</p>
      <pre style="background:#1c1917;color:#a8a29e;padding:16px;border-radius:8px;font-size:11px;line-height:1.6;overflow-x:auto;white-space:pre-wrap;word-break:break-all;">${tail.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</pre>
    </td>
  </tr>
  <tr>
    <td style="background:#1a1a1a;padding:20px 40px;text-align:center;">
      <p style="margin:0;color:#57534e;font-size:11px;">Cadejo Brewing Company · Sistema Automatizado EC2 · ${yr}</p>
    </td>
  </tr>
</table>
</td></tr>
</table>
</body>
</html>`;

  const asunto = OK
    ? `[CRON OK] ${JOB} — ${fecha}`
    : `[CRON FALLO] ${JOB} — ${fecha}`;

  const ses = new SESClient({
    region: process.env.SES_REGION ?? 'us-east-1',
    credentials: {
      accessKeyId:     process.env.AWS_ACCESS_KEY_ID,
      secretAccessKey: process.env.AWS_SECRET_ACCESS_KEY,
    },
  });

  await ses.send(new SendEmailCommand({
    Source:      `"Cadejo · Cron Monitor" <${process.env.MAIL_FROM_ADDRESS ?? 'notificaciones@cervezacadejo.com'}>`,
    Destination: { ToAddresses: [DEST] },
    Message: {
      Subject: { Data: asunto, Charset: 'UTF-8' },
      Body:    { Html: { Data: html, Charset: 'UTF-8' } },
    },
  }));

  console.log(`Notificación enviada a ${DEST} (${OK ? 'OK' : 'FALLO'})`);
}

main().catch(err => {
  console.error('Error enviando notificación:', err.message);
  process.exit(0);
});
