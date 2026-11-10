/**
 * reporte_semanal_altas_bajas.js
 * Envía a juanlopez@cervezacadejo.com el reporte semanal de altas y bajas.
 * Puerto Node.js de: php artisan rrhh:reporte-semanal-altas-bajas
 *
 * Uso: node database/reporte_semanal_altas_bajas.js [--semana=YYYY-MM-DD] [--email=otro@correo.com] [--dry-run]
 */
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const { Client } = require('pg');
const { SESClient, SendEmailCommand } = require('@aws-sdk/client-ses');

const DEST      = process.argv.find(a => a.startsWith('--email='))?.split('=')[1] ?? 'juanlopez@cervezacadejo.com';
const SEM_INPUT = process.argv.find(a => a.startsWith('--semana='))?.split('=')[1] ?? null;
const DRY_RUN   = process.argv.includes('--dry-run');

const cfgRrhh = {
  host:     process.env.DB_HOST_RRHH ?? process.env.DB_HOST,
  port:     parseInt(process.env.DB_PORT_RRHH ?? '5432'),
  database: process.env.DB_DATABASE_RRHH ?? 'rrhh_db',
  user:     process.env.DB_USERNAME_RRHH ?? process.env.DB_USERNAME ?? 'cadejo_admin',
  password: process.env.DB_PASSWORD_RRHH ?? process.env.DB_PASSWORD,
  ssl:      { rejectUnauthorized: false },
};

function calcSemana(input) {
  let inicio;
  if (input) {
    inicio = new Date(input + 'T00:00:00');
  } else {
    const hoy = new Date();
    const dia = hoy.getDay(); // 0=Dom
    const diasDesdeHoy = dia === 0 ? 6 : dia - 1;
    inicio = new Date(hoy);
    inicio.setDate(hoy.getDate() - diasDesdeHoy - 7);
    inicio.setHours(0, 0, 0, 0);
  }
  const fin = new Date(inicio);
  fin.setDate(inicio.getDate() + 6);
  return { inicio, fin };
}

function fmtDate(d) {
  if (!d) return '—';
  const dt = new Date(d);
  return `${String(dt.getUTCDate()).padStart(2, '0')}/${String(dt.getUTCMonth() + 1).padStart(2, '0')}/${dt.getUTCFullYear()}`;
}

function semanaLabel(ini, fin) {
  const M = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
  return `${ini.getUTCDate()} ${M[ini.getUTCMonth()]} – ${fin.getUTCDate()} ${M[fin.getUTCMonth()]} ${fin.getUTCFullYear()}`;
}

function buildHtml({ label, totalAltas, totalBajas, altas, bajas, altasPorSucursal, bajasPorSucursal }) {
  const yr = new Date().getFullYear();

  const renderSucTable = (rows, colorBorder, colorBg, colorHd, colorHdText) => rows.length <= 1 ? '' : `
    <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px;overflow:hidden;border:1px solid ${colorBorder};margin-bottom:12px;font-size:12px;">
      <tr>
        <td style="background:${colorBg};padding:7px 12px;font-size:10px;font-weight:700;color:${colorHdText};text-transform:uppercase;letter-spacing:1px;">Sucursal</td>
        <td style="background:${colorBg};padding:7px 12px;font-size:10px;font-weight:700;color:${colorHdText};text-transform:uppercase;letter-spacing:1px;text-align:right;">Total</td>
      </tr>
      ${rows.map((s, i) => `
      <tr style="${i % 2 !== 0 ? `background:${colorHd};` : ''}">
        <td style="padding:7px 12px;color:#292524;border-bottom:1px solid ${colorBorder};">${s.sucursal}</td>
        <td style="padding:7px 12px;color:${colorHdText};border-bottom:1px solid ${colorBorder};text-align:right;font-weight:600;">${s.total}</td>
      </tr>`).join('')}
    </table>`;

  const altasSection = totalAltas > 0 ? `
    <tr>
      <td style="background:#ffffff;padding:16px 40px 8px;">
        <p style="margin:0 0 10px;color:#166534;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">↑ Ingresos de Personal</p>
        ${renderSucTable(altasPorSucursal, '#dcfce7', '#dcfce7', '#f0fdf4', '#166534')}
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px;overflow:hidden;border:1px solid #dcfce7;font-size:12px;">
          <tr>
            ${['Fecha','Empleado','Cargo','Sucursal'].map(h => `<td style="background:#dcfce7;padding:8px 10px;font-size:10px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:1px;">${h}</td>`).join('')}
          </tr>
          ${altas.map((r, i) => `
          <tr style="${i % 2 !== 0 ? 'background:#f0fdf4;' : ''}">
            <td style="padding:7px 10px;color:#44403c;border-bottom:1px solid #dcfce7;white-space:nowrap;">${r.fecha}</td>
            <td style="padding:7px 10px;color:#292524;border-bottom:1px solid #dcfce7;">${r.empleado}</td>
            <td style="padding:7px 10px;color:#44403c;border-bottom:1px solid #dcfce7;">${r.cargo}</td>
            <td style="padding:7px 10px;color:#44403c;border-bottom:1px solid #dcfce7;">${r.sucursal}</td>
          </tr>`).join('')}
        </table>
      </td>
    </tr>` : `
    <tr>
      <td style="background:#ffffff;padding:16px 40px 8px;">
        <p style="margin:0 0 6px;color:#166534;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">↑ Ingresos de Personal</p>
        <p style="margin:0;color:#a8a29e;font-size:13px;">No se registraron ingresos esta semana.</p>
      </td>
    </tr>`;

  const tipoLabel = t => ({ renuncia: 'Renuncia', despido: 'Despido', mutuo_acuerdo: 'Mutuo acuerdo' }[t] ?? (t ? t.charAt(0).toUpperCase() + t.slice(1) : '—'));
  const tipoBadge = tipo => {
    if (tipo === 'Despido')  return `<span style="background:#fef2f2;color:#b91c1c;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;text-transform:uppercase;">Despido</span>`;
    if (tipo === 'Renuncia') return `<span style="background:#fff7ed;color:#c2410c;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;text-transform:uppercase;">Renuncia</span>`;
    return `<span style="color:#78716c;font-size:11px;">${tipo}</span>`;
  };

  const bajasSection = totalBajas > 0 ? `
    <tr>
      <td style="background:#ffffff;padding:8px 40px 32px;">
        <p style="margin:0 0 10px;color:#991b1b;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">↓ Desvinculaciones</p>
        ${renderSucTable(bajasPorSucursal, '#fecaca', '#fecaca', '#fef2f2', '#991b1b')}
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px;overflow:hidden;border:1px solid #fecaca;font-size:12px;">
          <tr>
            ${['Fecha','Empleado','Cargo','Sucursal','Tipo'].map(h => `<td style="background:#fecaca;padding:8px 10px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:1px;">${h}</td>`).join('')}
          </tr>
          ${bajas.map((r, i) => `
          <tr style="${i % 2 !== 0 ? 'background:#fef2f2;' : ''}">
            <td style="padding:7px 10px;color:#44403c;border-bottom:1px solid #fecaca;white-space:nowrap;">${r.fecha}</td>
            <td style="padding:7px 10px;color:#292524;border-bottom:1px solid #fecaca;">${r.empleado}</td>
            <td style="padding:7px 10px;color:#44403c;border-bottom:1px solid #fecaca;">${r.cargo}</td>
            <td style="padding:7px 10px;color:#44403c;border-bottom:1px solid #fecaca;">${r.sucursal}</td>
            <td style="padding:7px 10px;border-bottom:1px solid #fecaca;">${tipoBadge(tipoLabel(r.tipo))}</td>
          </tr>`).join('')}
        </table>
      </td>
    </tr>` : `
    <tr>
      <td style="background:#ffffff;padding:8px 40px 32px;">
        <p style="margin:0 0 6px;color:#991b1b;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">↓ Desvinculaciones</p>
        <p style="margin:0;color:#a8a29e;font-size:13px;">No se registraron desvinculaciones esta semana.</p>
      </td>
    </tr>`;

  return `<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <style>
    body{margin:0;padding:0;}table{border-collapse:collapse;}img{border:0;display:block;}
    @media(prefers-color-scheme:dark){
      .body-bg{background:#1c1917!important}.card-bg{background:#292524!important}
      .tbl-hd-g{background:#14532d!important;color:#bbf7d0!important}
      .tbl-hd-r{background:#7f1d1d!important;color:#fecaca!important}
      .tbl-row{background:#292524!important;color:#d6d3d1!important;border-bottom:1px solid #44403c!important}
      .stat-green{background:#14532d!important;border:1px solid #166534!important}
      .stat-red{background:#7f1d1d!important;border:1px solid #991b1b!important}
      .stat-num{color:#f5f5f4!important}
    }
  </style>
</head>
<body class="body-bg" style="margin:0;padding:0;background:#f5f0e8;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" class="body-bg" style="background:#f5f0e8;padding:32px 16px;">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" class="card-bg" style="max-width:660px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.12);">
  <tr>
    <td style="background:#1a1a1a;padding:32px 48px;text-align:center;">
      <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png" alt="Cadejo" width="80" style="display:block;margin:0 auto 16px;border-radius:50%;"/>
      <p style="margin:0 0 6px;color:#f59e0b;font-size:11px;letter-spacing:3px;text-transform:uppercase;font-weight:600;">Cadejo Brewing Company</p>
      <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:1px;">Gestión de Talento</h1>
    </td>
  </tr>
  <tr>
    <td style="background:#1d4ed8;padding:14px 48px;text-align:center;">
      <p style="margin:0;color:#ffffff;font-size:13px;font-weight:600;letter-spacing:1px;text-transform:uppercase;">Reporte Semanal de Altas y Bajas — ${label}</p>
    </td>
  </tr>
  <tr>
    <td class="card-bg" style="background:#ffffff;padding:28px 40px 16px;">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td width="50%" style="padding:4px 6px 4px 0;">
            <table width="100%" cellpadding="14" cellspacing="0" class="stat-green" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
              <tr><td style="text-align:center;">
                <p style="margin:0 0 4px;color:#166534;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Altas (Ingresos)</p>
                <p class="stat-num" style="margin:0;color:#14532d;font-size:32px;font-weight:800;">${totalAltas}</p>
              </td></tr>
            </table>
          </td>
          <td width="50%" style="padding:4px 0 4px 6px;">
            <table width="100%" cellpadding="14" cellspacing="0" class="stat-red" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">
              <tr><td style="text-align:center;">
                <p style="margin:0 0 4px;color:#991b1b;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Bajas (Desvinculaciones)</p>
                <p class="stat-num" style="margin:0;color:#7f1d1d;font-size:32px;font-weight:800;">${totalBajas}</p>
              </td></tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  ${altasSection}
  <tr><td style="height:20px;"></td></tr>
  ${bajasSection}
  <tr>
    <td style="background:#1a1a1a;padding:24px 40px;text-align:center;">
      <p style="margin:0;color:#78716c;font-size:11px;">Este reporte fue generado automáticamente por el sistema de Gestión de Talento.</p>
      <p style="margin:6px 0 0;color:#57534e;font-size:11px;">Cadejo Brewing Company · ${yr}</p>
    </td>
  </tr>
</table>
</td></tr>
</table>
</body>
</html>`;
}

async function main() {
  const { inicio, fin } = calcSemana(SEM_INPUT);
  const label = semanaLabel(inicio, fin);

  console.log(`\n=== Reporte Semanal Altas y Bajas — ${label} ===`);
  console.log(`Destino: ${DEST}${DRY_RUN ? ' [dry-run]' : ''}\n`);

  const rrhh = new Client(cfgRrhh);
  try {
    await rrhh.connect();
    console.log('PostgreSQL OK (rrhh_db)');

    const ini = inicio.toISOString().slice(0, 10);
    const fnl = fin.toISOString().slice(0, 10);

    const ingRes = await rrhh.query(`
      SELECT empleado_nombre, cargo_nombre, sucursal_nombre, fecha_ingreso
      FROM ingresos_personal
      WHERE fecha_ingreso BETWEEN $1 AND $2
      ORDER BY fecha_ingreso
    `, [ini, fnl]);

    const desRes = await rrhh.query(`
      SELECT empleado_nombre, cargo_nombre, sucursal_nombre, fecha_efectiva, tipo
      FROM desvinculaciones
      WHERE fecha_efectiva BETWEEN $1 AND $2
      ORDER BY fecha_efectiva
    `, [ini, fnl]);

    const altas = ingRes.rows.map(r => ({
      fecha:    fmtDate(r.fecha_ingreso),
      empleado: r.empleado_nombre ?? '—',
      cargo:    r.cargo_nombre    ?? '—',
      sucursal: r.sucursal_nombre ?? '—',
    }));

    const bajas = desRes.rows.map(r => ({
      fecha:    fmtDate(r.fecha_efectiva),
      empleado: r.empleado_nombre ?? '—',
      cargo:    r.cargo_nombre    ?? '—',
      sucursal: r.sucursal_nombre ?? '—',
      tipo:     r.tipo,
    }));

    const altasPorSucursal = Object.entries(
      altas.reduce((m, r) => { m[r.sucursal] = (m[r.sucursal] ?? 0) + 1; return m; }, {})
    ).map(([sucursal, total]) => ({ sucursal, total }))
      .sort((a, b) => b.total - a.total);

    const bajasPorSucursal = Object.entries(
      bajas.reduce((m, r) => { m[r.sucursal] = (m[r.sucursal] ?? 0) + 1; return m; }, {})
    ).map(([sucursal, total]) => ({ sucursal, total }))
      .sort((a, b) => b.total - a.total);

    console.log(`Altas: ${altas.length} | Bajas: ${bajas.length}`);

    if (DRY_RUN) {
      console.log('Dry-run: correo no enviado.');
      return;
    }

    const html = buildHtml({ label, totalAltas: altas.length, totalBajas: bajas.length, altas, bajas, altasPorSucursal, bajasPorSucursal });

    const ses = new SESClient({
      region: process.env.SES_REGION ?? 'us-east-1',
      credentials: {
        accessKeyId:     process.env.AWS_ACCESS_KEY_ID,
        secretAccessKey: process.env.AWS_SECRET_ACCESS_KEY,
      },
    });

    await ses.send(new SendEmailCommand({
      Source:      `"Cadejo Brewing Company" <${process.env.MAIL_FROM_ADDRESS ?? 'notificaciones@cervezacadejo.com'}>`,
      Destination: { ToAddresses: [DEST] },
      Message: {
        Subject: { Data: `Reporte Semanal Altas y Bajas — ${label}`, Charset: 'UTF-8' },
        Body:    { Html: { Data: html, Charset: 'UTF-8' } },
      },
    }));

    console.log(`Reporte enviado a ${DEST}`);

  } finally {
    await rrhh.end().catch(() => {});
  }
}

main().catch(err => { console.error(err); process.exit(1); });
