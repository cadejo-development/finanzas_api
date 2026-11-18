/**
 * check_sync_facturas_brilo.js
 *
 * Verifica que todas las sucursales de Brilo hayan sincronizado facturas
 * del día anterior en olVentas.dbo.maeFacturas (central).
 * Si alguna sucursal tiene 0 facturas → envía alerta por correo vía AWS SES.
 *
 * Uso:
 *   node database/check_sync_facturas_brilo.js            # día anterior
 *   node database/check_sync_facturas_brilo.js --fecha=2026-11-17
 *   node database/check_sync_facturas_brilo.js --dry-run
 *
 * Crontab EC2 (6:00 AM hora SV):
 *   0 11 * * * node /var/app/current/database/check_sync_facturas_brilo.js >> /var/log/check-sync-facturas.log 2>&1
 *   (UTC-6 → 06:00 SV = 12:00 UTC)
 */
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const sql = require('mssql');
const { SESClient, SendEmailCommand } = require('@aws-sdk/client-ses');

// ── Argumentos ───────────────────────────────────────────────────────────────
const args     = process.argv.slice(2);
const DRY_RUN  = args.includes('--dry-run');
const FORCE    = args.includes('--force');
const fechaArg = args.find(a => a.startsWith('--fecha='))?.split('=')[1] ?? null;
const toArg    = args.find(a => a.startsWith('--to='))?.split('=')[1] ?? null;

function ayer() {
  if (fechaArg) return fechaArg;
  const d = new Date();
  d.setDate(d.getDate() - 1);
  return d.toISOString().slice(0, 10);
}

// ── Sucursales monitoreadas por cjavId (campo más preciso que sucId)
// cjavId identifica el punto de venta exacto; sucId puede compartirse entre
// sucursales en el mismo servidor (ej: Aeropuerto 2 y Malcriadas AE2 comparten sucId=8)
const SUCURSALES = {
   1: 'FAB - ZONA ROSA',
   4: 'RES - ZONA ROSA',
   5: 'RES - LA LIBERTAD',
   7: 'RES - AEROPUERTO #1',
   8: 'RES - AEROPUERTO #2',
  12: 'RES - PASEO VENECIA',
  13: 'RES - SANTA ELENA',
  14: 'RES - HUIZUCAR',
  15: 'RES - OPICO',
  16: 'RES - MALCRIADAS AE2',
  20: 'RES - CASA GUIROLA',
};

const DESTINATARIOS = [
  'javiermejia@cervezacadejo.com',
  'marcelaorellana@cervezacadejo.com',
];

const mssqlCfg = {
  user:     process.env.DB_USERNAME_ORIGEN,
  password: process.env.DB_PASSWORD_ORIGEN,
  server:   process.env.DB_HOST_ORIGEN,
  port:     2033,
  database: 'olVentas',
  options:  { trustServerCertificate: true, encrypt: false, connectTimeout: 30000, requestTimeout: 60000 },
};

// ── Email HTML ───────────────────────────────────────────────────────────────
function buildEmail(fecha, faltantes, resumen) {
  const fechaSV = new Date().toLocaleString('es-SV', { timeZone: 'America/El_Salvador' });
  const yr = new Date().getFullYear();

  const filasResumen = resumen.map(r => `
    <tr style="${r.sin_facturas ? 'background:#fef2f2;' : ''}">
      <td style="padding:9px 14px;font-size:13px;color:#292524;">${r.nombre}</td>
      <td style="padding:9px 14px;font-size:13px;text-align:center;font-weight:600;color:${r.sin_facturas ? '#dc2626' : '#16a34a'};">
        ${r.sin_facturas ? '✗ Sin facturas' : `✓ ${r.total_facturas} facturas`}
      </td>
      <td style="padding:9px 14px;font-size:13px;text-align:right;color:#44403c;">
        ${r.sin_facturas ? '—' : `$${Number(r.total_venta ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}`}
      </td>
    </tr>`).join('');

  return `<!DOCTYPE html>
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
    <td style="background:${faltantes.length ? '#dc2626' : '#16a34a'};padding:14px 40px;text-align:center;">
      <p style="margin:0;color:#ffffff;font-size:14px;font-weight:700;letter-spacing:1px;text-transform:uppercase;">
        ${faltantes.length ? '⚠ Alerta de Sincronización Brilo' : '✓ Sincronización Brilo — Todo OK'}
      </p>
    </td>
  </tr>
  <tr>
    <td style="padding:28px 40px 20px;">
      <p style="margin:0 0 6px;font-size:15px;font-weight:700;color:#1a1a1a;">
        ${faltantes.length ? `Facturas no sincronizadas — ${fecha}` : `Reporte de estatus — ${fecha}`}
      </p>
      <p style="margin:0 0 20px;font-size:13px;color:#78716c;">
        ${faltantes.length
          ? `Las siguientes sucursales <strong>no registraron facturas</strong> para el día <strong>${fecha}</strong> en la base de datos central de Brilo (olVentas):`
          : `Todas las sucursales registraron facturas correctamente para el día <strong>${fecha}</strong> en la base de datos central de Brilo (olVentas).`
        }
      </p>

      <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid ${faltantes.length ? '#fecaca' : '#bbf7d0'};border-radius:8px;overflow:hidden;margin-bottom:24px;">
        <tr style="background:${faltantes.length ? '#fee2e2' : '#dcfce7'};">
          <td style="padding:10px 14px;font-size:11px;font-weight:700;color:${faltantes.length ? '#991b1b' : '#166534'};text-transform:uppercase;letter-spacing:1px;">Sucursal</td>
          <td style="padding:10px 14px;font-size:11px;font-weight:700;color:${faltantes.length ? '#991b1b' : '#166534'};text-transform:uppercase;letter-spacing:1px;text-align:center;">Estado</td>
          <td style="padding:10px 14px;font-size:11px;font-weight:700;color:${faltantes.length ? '#991b1b' : '#166534'};text-transform:uppercase;letter-spacing:1px;text-align:right;">Venta Total</td>
        </tr>
        ${filasResumen}
      </table>

      <p style="margin:0 0 4px;font-size:13px;color:#44403c;">
        ${faltantes.length
          ? `<strong>${faltantes.length}</strong> de <strong>${Object.keys(SUCURSALES).length}</strong> sucursales sin sincronizar.`
          : `<strong>${Object.keys(SUCURSALES).length}</strong> de <strong>${Object.keys(SUCURSALES).length}</strong> sucursales sincronizadas correctamente.`
        }
      </p>
      <p style="margin:0;font-size:12px;color:#a8a29e;">Reporte generado: ${fechaSV}</p>
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
}

// ── Main ─────────────────────────────────────────────────────────────────────
async function main() {
  const fecha = ayer();
  console.log(`[${new Date().toISOString()}] check_sync_facturas_brilo — verificando ${fecha}${DRY_RUN ? ' [DRY RUN]' : ''}`);

  const pool = await sql.connect(mssqlCfg);

  const result = await pool.request()
    .input('fecha', sql.VarChar(10), fecha)
    .query(`
      SELECT
        MFA.cjavId,
        COUNT(*)                                                                AS total_facturas,
        SUM(MFA.mfaSumasAfecto * (1 + MFA.mfaPorcentIVA) + MFA.mfaSumasExento) AS total_venta
      FROM olVentas.dbo.maeFacturas MFA WITH (NOLOCK)
      WHERE MFA.mfaPosteada = 1
        AND MFA.mfaAnulada  = 0
        AND CONVERT(DATE, MFA.mfaFecha) = @fecha
        AND MFA.cjavId IN (${Object.keys(SUCURSALES).join(',')})
      GROUP BY MFA.cjavId
    `);

  await pool.close();

  // Comparar resultado vs sucursales esperadas
  const conFacturas = new Set(result.recordset.map(r => r.cjavId));
  const faltantes   = Object.entries(SUCURSALES)
    .filter(([id]) => !conFacturas.has(Number(id)))
    .map(([, nombre]) => nombre);

  // Construir resumen completo para la tabla del correo
  const resumen = Object.entries(SUCURSALES).map(([id, nombre]) => {
    const row = result.recordset.find(r => r.cjavId === Number(id));
    return {
      nombre,
      sin_facturas:   !row,
      total_facturas: row?.total_facturas ?? 0,
      total_venta:    row?.total_venta    ?? 0,
    };
  });

  // Log resumen
  resumen.forEach(r => {
    const estado = r.sin_facturas ? '✗ SIN FACTURAS' : `✓ ${r.total_facturas} facturas  $${Number(r.total_venta).toFixed(2)}`;
    console.log(`  ${r.nombre.padEnd(28)} ${estado}`);
  });

  if (!faltantes.length && !FORCE) {
    console.log(`[OK] Todas las sucursales tienen facturas para ${fecha}.`);
    return;
  }

  if (faltantes.length)
    console.log(`[ALERTA] ${faltantes.length} sucursal(es) sin facturas: ${faltantes.join(', ')}`);
  else
    console.log(`[FORCE] Sin alertas — enviando reporte de estatus completo.`);

  if (DRY_RUN) {
    console.log('[DRY RUN] Correo NO enviado.');
    return;
  }

  const destinos = toArg ? [toArg] : DESTINATARIOS;
  const html     = buildEmail(fecha, faltantes, resumen);
  const asunto   = faltantes.length
    ? `[Brilo] ⚠ ${faltantes.length} sucursal(es) sin sincronizar — ${fecha}`
    : `[Brilo] ✓ Todas las sucursales sincronizadas — ${fecha}`;

  const ses = new SESClient({
    region: process.env.SES_REGION ?? 'us-east-1',
    credentials: {
      accessKeyId:     process.env.AWS_ACCESS_KEY_ID,
      secretAccessKey: process.env.AWS_SECRET_ACCESS_KEY,
    },
  });

  await ses.send(new SendEmailCommand({
    Source:      `"Cadejo · Alertas Brilo" <${process.env.MAIL_FROM_ADDRESS ?? 'notificaciones@cervezacadejo.com'}>`,
    Destination: { ToAddresses: destinos },
    Message: {
      Subject: { Data: asunto,  Charset: 'UTF-8' },
      Body:    { Html: { Data: html, Charset: 'UTF-8' } },
    },
  }));

  console.log(`[OK] Correo enviado a ${destinos.join(', ')}`);
}

main().catch(err => {
  console.error(`[ERROR] ${err.message}`);
  process.exit(1);
});
