/**
 * reporte_mensual_amonestaciones.js
 * Envía a juanlopez@cervezacadejo.com el reporte mensual de amonestaciones.
 * Solo corre el último día del mes (o con --force).
 * Puerto Node.js de: php artisan rrhh:reporte-mensual-amonestaciones
 *
 * Uso: node database/reporte_mensual_amonestaciones.js [--mes=YYYY-MM] [--email=otro@correo.com] [--dry-run] [--force]
 */
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const { Client } = require('pg');
const { SESClient, SendEmailCommand } = require('@aws-sdk/client-ses');

const DEST      = process.argv.find(a => a.startsWith('--email='))?.split('=')[1] ?? 'juanlopez@cervezacadejo.com';
const MES_INPUT = process.argv.find(a => a.startsWith('--mes='))?.split('=')[1] ?? null;
const DRY_RUN   = process.argv.includes('--dry-run');
const FORCE     = process.argv.includes('--force');

const cfgCore = {
  host:     process.env.DB_HOST,
  port:     parseInt(process.env.DB_PORT ?? '5432'),
  database: process.env.DB_DATABASE ?? 'core_db',
  user:     process.env.DB_USERNAME ?? 'cadejo_admin',
  password: process.env.DB_PASSWORD,
  ssl:      { rejectUnauthorized: false },
};

const cfgRrhh = {
  host:     process.env.DB_HOST_RRHH ?? process.env.DB_HOST,
  port:     parseInt(process.env.DB_PORT_RRHH ?? '5432'),
  database: process.env.DB_DATABASE_RRHH ?? 'rrhh_db',
  user:     process.env.DB_USERNAME_RRHH ?? process.env.DB_USERNAME ?? 'cadejo_admin',
  password: process.env.DB_PASSWORD_RRHH ?? process.env.DB_PASSWORD,
  ssl:      { rejectUnauthorized: false },
};

function isUltimoDia() {
  const hoy = new Date();
  const manana = new Date(hoy);
  manana.setDate(hoy.getDate() + 1);
  return manana.getMonth() !== hoy.getMonth();
}

function calcMes(input) {
  let inicio;
  if (input) {
    const [y, m] = input.split('-').map(Number);
    inicio = new Date(y, m - 1, 1);
  } else {
    const hoy = new Date();
    inicio = new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1);
  }
  const fin = new Date(inicio.getFullYear(), inicio.getMonth() + 1, 0);
  return { inicio, fin };
}

function mesLabel(inicio) {
  const meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
  return `${meses[inicio.getMonth()]} ${inicio.getFullYear()}`;
}

function fmtDate(d) {
  if (!d) return '—';
  const dt = new Date(d);
  return `${String(dt.getUTCDate()).padStart(2, '0')}/${String(dt.getUTCMonth() + 1).padStart(2, '0')}/${dt.getUTCFullYear()}`;
}

function buildHtml({ label, total, porSucursal, porTipo, detalle }) {
  const yr = new Date().getFullYear();

  const statsTipo = porTipo.length === 0 ? '' : porTipo.map(t => `
    <td style="padding:4px 0 4px 6px;">
      <table width="100%" cellpadding="12" cellspacing="0" style="background:#fafaf9;border:1px solid #e7e5e4;border-radius:8px;">
        <tr><td style="text-align:center;">
          <p style="margin:0 0 4px;color:#78716c;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;">${t.tipo}</p>
          <p style="margin:0;color:#292524;font-size:24px;font-weight:700;">${t.total}</p>
        </td></tr>
      </table>
    </td>`).join('');

  const sucSection = porSucursal.length === 0 ? '' : `
    <tr>
      <td style="background:#ffffff;padding:0 40px 24px;">
        <p style="margin:0 0 10px;color:#1a1a1a;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">Por Sucursal / Área</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;border:1px solid #e7e5e4;">
          <tr>
            <td style="background:#f5f0e8;padding:10px 14px;font-size:11px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:1px;">Sucursal</td>
            <td style="background:#f5f0e8;padding:10px 14px;font-size:11px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:1px;text-align:right;">Amonestaciones</td>
          </tr>
          ${porSucursal.map((s, i) => `
          <tr style="${i % 2 !== 0 ? 'background:#fafaf9;' : ''}">
            <td style="padding:9px 14px;font-size:13px;color:#292524;border-bottom:1px solid #e7e5e4;">${s.sucursal}</td>
            <td style="padding:9px 14px;font-size:13px;color:#292524;border-bottom:1px solid #e7e5e4;text-align:right;font-weight:600;">${s.total}</td>
          </tr>`).join('')}
        </table>
      </td>
    </tr>`;

  const sancionBadge = s => {
    if (s === 'suspension') return `<span style="background:#fef2f2;color:#b91c1c;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;text-transform:uppercase;">Suspensión</span>`;
    if (s === 'escrita')    return `<span style="background:#fff7ed;color:#c2410c;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;text-transform:uppercase;">Escrita</span>`;
    return `<span style="color:#78716c;font-size:11px;">Verbal</span>`;
  };

  const detalleSection = detalle.length === 0 ? `
    <tr>
      <td style="background:#ffffff;padding:0 40px 32px;text-align:center;">
        <p style="color:#a8a29e;font-size:14px;">No se registraron amonestaciones en ${label}.</p>
      </td>
    </tr>` : `
    <tr>
      <td style="background:#ffffff;padding:0 40px 32px;">
        <p style="margin:0 0 10px;color:#1a1a1a;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">Detalle</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;border:1px solid #e7e5e4;font-size:12px;">
          <tr>
            ${['Fecha','Empleado','Sucursal','Tipo Falta','Sanción'].map(h => `<td style="background:#f5f0e8;padding:9px 10px;font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:1px;">${h}</td>`).join('')}
          </tr>
          ${detalle.map((r, i) => `
          <tr style="${i % 2 !== 0 ? 'background:#fafaf9;' : ''}">
            <td style="padding:8px 10px;color:#44403c;border-bottom:1px solid #e7e5e4;white-space:nowrap;">${r.fecha}</td>
            <td style="padding:8px 10px;color:#292524;border-bottom:1px solid #e7e5e4;">${r.empleado}</td>
            <td style="padding:8px 10px;color:#44403c;border-bottom:1px solid #e7e5e4;">${r.sucursal}</td>
            <td style="padding:8px 10px;color:#44403c;border-bottom:1px solid #e7e5e4;">${r.tipo_falta}</td>
            <td style="padding:8px 10px;border-bottom:1px solid #e7e5e4;">${sancionBadge(r.sancion)}</td>
          </tr>`).join('')}
        </table>
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
      .tbl-hd{background:#1c1919!important;color:#f59e0b!important}
      .tbl-row{background:#292524!important;color:#d6d3d1!important;border-bottom:1px solid #44403c!important}
    }
  </style>
</head>
<body class="body-bg" style="margin:0;padding:0;background:#f5f0e8;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" class="body-bg" style="background:#f5f0e8;padding:32px 16px;">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" class="card-bg" style="max-width:640px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.12);">
  <tr>
    <td style="background:#1a1a1a;padding:32px 48px;text-align:center;">
      <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png" alt="Cadejo" width="80" style="display:block;margin:0 auto 16px;border-radius:50%;"/>
      <p style="margin:0 0 6px;color:#f59e0b;font-size:11px;letter-spacing:3px;text-transform:uppercase;font-weight:600;">Cadejo Brewing Company</p>
      <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:1px;">Gestión de Talento</h1>
    </td>
  </tr>
  <tr>
    <td style="background:#b45309;padding:14px 48px;text-align:center;">
      <p style="margin:0;color:#ffffff;font-size:13px;font-weight:600;letter-spacing:1px;text-transform:uppercase;">Reporte Mensual de Amonestaciones — ${label}</p>
    </td>
  </tr>
  <tr>
    <td class="card-bg" style="background:#ffffff;padding:32px 40px 8px;">
      <p style="margin:0;color:#555555;font-size:15px;line-height:1.65;">
        A continuación el resumen de amonestaciones registradas durante <strong>${label}</strong>.
      </p>
    </td>
  </tr>
  <tr>
    <td class="card-bg" style="background:#ffffff;padding:16px 40px 24px;">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td width="33%" style="padding:4px 6px 4px 0;">
            <table width="100%" cellpadding="12" cellspacing="0" style="background:#fef9f0;border:1px solid #f3d9a5;border-radius:8px;">
              <tr><td style="text-align:center;">
                <p style="margin:0 0 4px;color:#92400e;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Total</p>
                <p style="margin:0;color:#1a1a1a;font-size:28px;font-weight:800;">${total}</p>
              </td></tr>
            </table>
          </td>
          ${statsTipo}
        </tr>
      </table>
    </td>
  </tr>
  ${sucSection}
  ${detalleSection}
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
  if (!FORCE && !DRY_RUN && !MES_INPUT && !isUltimoDia()) {
    console.log('Hoy no es el último día del mes. Usa --force para ejecutar de todas formas.');
    return;
  }

  const { inicio, fin } = calcMes(MES_INPUT);
  const label = mesLabel(inicio);

  console.log(`\n=== Reporte Mensual Amonestaciones — ${label} ===`);
  console.log(`Destino: ${DEST}${DRY_RUN ? ' [dry-run]' : ''}\n`);

  const core = new Client(cfgCore);
  const rrhh = new Client(cfgRrhh);

  try {
    await core.connect();
    await rrhh.connect();
    console.log('PostgreSQL OK (core_db + rrhh_db)');

    const ini = inicio.toISOString().slice(0, 10);
    const fnl = fin.toISOString().slice(0, 10);

    // Amonestaciones del período con tipo de falta
    const amRes = await rrhh.query(`
      SELECT a.empleado_id, a.fecha_amonestacion, a.tipo_sancion, tf.nombre AS tipo_falta_nombre
      FROM amonestaciones a
      LEFT JOIN tipos_falta tf ON tf.id = a.tipo_falta_id
      WHERE a.fecha_amonestacion BETWEEN $1 AND $2
      ORDER BY a.fecha_amonestacion
    `, [ini, fnl]);

    const total = amRes.rows.length;
    console.log(`Total amonestaciones: ${total}`);

    // Empleados con cargo y sucursal desde core_db
    const empIds = [...new Set(amRes.rows.map(r => r.empleado_id))];
    let empleados = {};
    if (empIds.length > 0) {
      const empRes = await core.query(`
        SELECT e.id, e.nombres, e.apellidos, c.nombre AS cargo, s.nombre AS sucursal
        FROM empleados e
        LEFT JOIN cargos c    ON c.id = e.cargo_id
        LEFT JOIN sucursales s ON s.id = e.sucursal_id
        WHERE e.id = ANY($1)
      `, [empIds]);
      for (const r of empRes.rows) {
        empleados[r.id] = r;
      }
    }

    const detalle = amRes.rows.map(r => {
      const emp = empleados[r.empleado_id];
      const nombre = emp ? `${emp.nombres ?? ''} ${emp.apellidos ?? ''}`.trim() : `Empleado #${r.empleado_id}`;
      return {
        fecha:      fmtDate(r.fecha_amonestacion),
        empleado:   nombre || `Empleado #${r.empleado_id}`,
        sucursal:   emp?.sucursal ?? '—',
        tipo_falta: r.tipo_falta_nombre ?? '—',
        sancion:    r.tipo_sancion ?? 'verbal',
      };
    });

    const sancionLabels = { verbal: 'Verbal', escrita: 'Escrita', suspension: 'Suspensión' };
    const porTipoMap = {};
    for (const r of detalle) {
      const lbl = sancionLabels[r.sancion] ?? (r.sancion.charAt(0).toUpperCase() + r.sancion.slice(1));
      porTipoMap[lbl] = (porTipoMap[lbl] ?? 0) + 1;
    }
    const porTipo = Object.entries(porTipoMap)
      .map(([tipo, total]) => ({ tipo, total }))
      .sort((a, b) => b.total - a.total);

    const porSucursalMap = {};
    for (const r of detalle) {
      porSucursalMap[r.sucursal] = (porSucursalMap[r.sucursal] ?? 0) + 1;
    }
    const porSucursal = Object.entries(porSucursalMap)
      .map(([sucursal, total]) => ({ sucursal, total }))
      .sort((a, b) => b.total - a.total);

    if (DRY_RUN) {
      console.log('Por tipo:', porTipo);
      console.log('Por sucursal:', porSucursal);
      console.log('Dry-run: correo no enviado.');
      return;
    }

    const html = buildHtml({ label, total, porSucursal, porTipo, detalle });

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
        Subject: { Data: `Reporte Mensual de Amonestaciones — ${label}`, Charset: 'UTF-8' },
        Body:    { Html: { Data: html, Charset: 'UTF-8' } },
      },
    }));

    console.log(`Reporte enviado a ${DEST}`);

  } finally {
    await core.end().catch(() => {});
    await rrhh.end().catch(() => {});
  }
}

main().catch(err => { console.error(err); process.exit(1); });
