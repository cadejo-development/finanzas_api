/**
 * Sync de facturas Brilo → pagos_db.brilo_facturas
 *
 * Lee olCompras.dbo.maeCompras (posteadas, no anuladas) con JOIN a
 * olComun.dbo.CentrosCosto y olComun.dbo.Proveedores para desnormalizar
 * los nombres al momento de la sincronización.
 *
 * Uso:
 *   node database/sync_facturas_brilo.js              # últimos 30 días
 *   node database/sync_facturas_brilo.js --dias=7
 *   node database/sync_facturas_brilo.js --desde=2026-01-01   # backfill completo
 *   node database/sync_facturas_brilo.js --dry-run
 */
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const sql = require('mssql');
const { Pool } = require('pg');

// ── Argumentos ──────────────────────────────────────────────────────────────
const args = process.argv.slice(2);
const DRY_RUN   = args.includes('--dry-run');
const diasArg   = args.find(a => a.startsWith('--dias='));
const desdeArg  = args.find(a => a.startsWith('--desde='));
const DIAS      = diasArg  ? parseInt(diasArg.split('=')[1], 10)  : 30;
const DESDE_FIX = desdeArg ? desdeArg.split('=')[1] : null;

function fechaDesde() {
  if (DESDE_FIX) return DESDE_FIX;
  const d = new Date();
  d.setDate(d.getDate() - DIAS);
  return d.toISOString().slice(0, 10);
}

// ── Conexiones ───────────────────────────────────────────────────────────────
const mssqlCfg = {
  user: process.env.DB_USERNAME_ORIGEN,
  password: process.env.DB_PASSWORD_ORIGEN,
  server: process.env.DB_HOST_ORIGEN,
  port: 2033,
  database: 'olCompras',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 30000, requestTimeout: 180000 },
};

const pg = new Pool({
  host:     process.env.DB_HOST_PAGOS     || process.env.DB_HOST,
  port:     5432,
  database: process.env.DB_DATABASE_PAGOS,
  user:     process.env.DB_USERNAME_PAGOS  || process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD_PAGOS  || process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
});

// Nombres de sucursales Brilo (informativo; no bloquea si no existe)
const SUCURSAL_BRILO = {
   1: 'CASA MATRIZ',
   3: 'ZONA ROSA',
   6: 'LA LIBERTAD',
   7: 'AEROPUERTO 1',
   8: 'AEROPUERTO 2',
  10: 'PASEO VENECIA',
  11: 'SANTA ELENA',
  12: 'HUIZUCAR',
  13: 'OPICO',
  16: 'MALCRIADAS',
  19: 'CASA GUIROLA',
};

async function main() {
  const desde = fechaDesde();
  const hasta = new Date().toISOString().slice(0, 10);

  console.log(`=== Sync Facturas Brilo${DRY_RUN ? ' [DRY RUN]' : ''} ===`);
  console.log(`Rango: ${desde} → ${hasta}`);

  const pool = await sql.connect(mssqlCfg);

  const result = await pool.request()
    .input('desde', sql.VarChar(10), desde)
    .input('hasta', sql.VarChar(10), hasta)
    .query(`
      SELECT
        m.mcoId,
        CONVERT(varchar(10), m.mcoFecha, 23)      AS fecha_doc,
        m.mcoFechaHoraCreado                       AS fecha_creado,
        m.mcoTipoDoc,
        m.mcoNumDoc,
        m.mcoConcepto,
        m.sucId,
        m.cecoId,
        c.cecoNombre,
        m.cecoIdSub,
        cs.cecoNombre                              AS cecoSubNombre,
        m.prvId,
        p.prvNombre,
        ISNULL(m.mcoSumasAfecto, 0)               AS monto_afecto,
        ISNULL(m.mcoSumasExento, 0)               AS monto_exento
      FROM maeCompras m
      LEFT JOIN olComun.dbo.CentrosCosto c  ON c.cecoId  = m.cecoId
      LEFT JOIN olComun.dbo.CentrosCosto cs ON cs.cecoId = m.cecoIdSub
      LEFT JOIN olComun.dbo.Proveedores  p  ON p.prvId   = m.prvId
      WHERE m.mcoPosteada = 1
        AND m.mcoAnulada  = 0
        AND CONVERT(varchar(10), m.mcoFecha, 23) >= @desde
        AND CONVERT(varchar(10), m.mcoFecha, 23) <= @hasta
      ORDER BY m.mcoFechaHoraCreado
    `);

  await pool.close();

  const rows = result.recordset;
  console.log(`Facturas encontradas: ${rows.length}`);

  if (DRY_RUN || !rows.length) {
    console.log(DRY_RUN ? 'Dry run — sin cambios.' : 'Nada que sincronizar.');
    await pg.end();
    return;
  }

  const BATCH = 200;
  let upserted = 0;

  for (let i = 0; i < rows.length; i += BATCH) {
    const lote = rows.slice(i, i + BATCH);
    await Promise.all(lote.map(r => pg.query(`
      INSERT INTO brilo_facturas (
        mco_id, fecha_doc, fecha_creado, tipo_doc, num_doc, concepto,
        suc_id_brilo, sucursal_nombre,
        ceco_id, ceco_nombre, ceco_sub_id, ceco_sub_nombre,
        prv_id, prv_nombre,
        monto_afecto, monto_exento,
        synced_at, created_at, updated_at
      ) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,NOW(),NOW(),NOW())
      ON CONFLICT (mco_id) DO UPDATE SET
        fecha_doc       = EXCLUDED.fecha_doc,
        fecha_creado    = EXCLUDED.fecha_creado,
        concepto        = EXCLUDED.concepto,
        ceco_id         = EXCLUDED.ceco_id,
        ceco_nombre     = EXCLUDED.ceco_nombre,
        ceco_sub_id     = EXCLUDED.ceco_sub_id,
        ceco_sub_nombre = EXCLUDED.ceco_sub_nombre,
        prv_nombre      = EXCLUDED.prv_nombre,
        monto_afecto    = EXCLUDED.monto_afecto,
        monto_exento    = EXCLUDED.monto_exento,
        synced_at       = NOW(),
        updated_at      = NOW()
    `, [
      r.mcoId,
      r.fecha_doc || null,
      r.fecha_creado || null,
      r.mcoTipoDoc || null,
      r.mcoNumDoc  || null,
      r.mcoConcepto || null,
      r.sucId || null,
      r.sucId ? (SUCURSAL_BRILO[r.sucId] || `Suc ${r.sucId}`) : null,
      r.cecoId || null,
      r.cecoNombre || null,
      r.cecoIdSub || null,
      r.cecoSubNombre || null,
      r.prvId || null,
      r.prvNombre || null,
      r.monto_afecto,
      r.monto_exento,
    ])));
    upserted += lote.length;
    process.stdout.write(`\r  → ${upserted}/${rows.length}`);
  }

  console.log(`\n✓ ${upserted} facturas sincronizadas.`);
  await pg.end();
}

main().catch(e => { console.error('\nFATAL:', e.message); process.exit(1); });
