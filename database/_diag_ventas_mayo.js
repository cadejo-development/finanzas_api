/**
 * _diag_ventas_mayo.js
 *
 * DIAGNÓSTICO — Ventas Mayo 1-11 todas las sucursales
 *
 * Muestra:
 *  1. Sucursales en PostgreSQL (core_db)
 *  2. Registros actuales en ventas_semanales (compras_db)
 *  3. Sucursales y ventas 01-11 Mayo en Brilo por día
 *  4. Resumen: cabeceras, filas detalle, peso estimado
 *
 * Uso:
 *   node database/_diag_ventas_mayo.js
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const DESDE = '2026-05-01';
const HASTA = '2026-05-11';

const cfgRst = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olRestaurante',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};
const pgBase = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432, user: 'cadejo_admin', password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false }, connectionTimeoutMillis: 30000,
};

const hr  = (c = '─') => console.log(c.repeat(75));
const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s  => console.log(`[${ts()}] ${s}`);
const fmt = n  => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
const d2s = d  => (d instanceof Date ? d.toISOString() : String(d)).slice(0, 10);

async function main() {
  hr('═');
  log('DIAGNÓSTICO — Ventas Brilo → PostgreSQL | 01-11 Mayo 2026');
  hr('═');

  // ── PostgreSQL ─────────────────────────────────────────────────────────────
  log('\n📦 Conectando PostgreSQL...');
  const pgCore = new Pool({ ...pgBase, database: 'core_db' });
  const pgComp = new Pool({ ...pgBase, database: 'compras_db' });
  const coreClient = await pgCore.connect();
  const compClient = await pgComp.connect();
  log('   OK\n');

  // Sucursales desde core_db
  const pgSucs    = await coreClient.query(`SELECT id, codigo, nombre FROM sucursales ORDER BY id`);
  const pgSucMap  = Object.fromEntries(pgSucs.rows.map(s => [Number(s.id), s.nombre]));

  hr();
  console.log('  SUCURSALES EN POSTGRESQL (core_db)');
  hr();
  console.log(`  ${'ID'.padStart(4)}  ${'Código'.padEnd(10)}  Nombre`);
  for (const s of pgSucs.rows) {
    console.log(`  ${String(s.id).padStart(4)}  ${(s.codigo || '').padEnd(10)}  ${s.nombre}`);
  }

  // Estado actual ventas_semanales
  hr();
  console.log('  REGISTROS ACTUALES EN ventas_semanales (compras_db)');
  hr();
  const pgVentas = await compClient.query(`
    SELECT vs.sucursal_id, vs.semana_inicio,
           COUNT(vd.id) AS platos,
           ROUND(COALESCE(SUM(vd.total), 0)::numeric, 2) AS total_usd
    FROM ventas_semanales vs
    LEFT JOIN ventas_semanales_detalle vd ON vd.venta_semanal_id = vs.id
    GROUP BY vs.sucursal_id, vs.semana_inicio
    ORDER BY vs.sucursal_id, vs.semana_inicio
  `);

  if (pgVentas.rows.length === 0) {
    log('  (sin registros)\n');
  } else {
    console.log(`  ${'SucID'.padStart(5)}  ${'Sucursal'.padEnd(32)}  ${'Fecha'.padEnd(12)}  ${'Platos'.padStart(7)}  ${'Total'.padStart(12)}`);
    let totalFil = 0;
    for (const r of pgVentas.rows) {
      totalFil += Number(r.platos);
      const nom = pgSucMap[r.sucursal_id] || `id=${r.sucursal_id}`;
      console.log(`  ${String(r.sucursal_id).padStart(5)}  ${nom.slice(0, 31).padEnd(32)}  ${d2s(r.semana_inicio).padEnd(12)}  ${String(r.platos).padStart(7)}  ${('$' + Number(r.total_usd).toFixed(2)).padStart(12)}`);
    }
    const totVS = await compClient.query('SELECT COUNT(*) AS c FROM ventas_semanales');
    const sizes = await compClient.query(`
      SELECT pg_size_pretty(pg_total_relation_size('ventas_semanales')) AS vs,
             pg_size_pretty(pg_total_relation_size('ventas_semanales_detalle')) AS vd
    `).catch(() => ({ rows: [{ vs: 'n/a', vd: 'n/a' }] }));
    console.log(`\n  → ${totVS.rows[0].c} cabeceras, ${fmt(totalFil)} filas detalle actuales`);
    console.log(`  → Peso actual: cabeceras=${sizes.rows[0].vs} | detalle=${sizes.rows[0].vd}\n`);
  }

  // ── SQL Server ─────────────────────────────────────────────────────────────
  hr();
  log('🔗 Conectando Brilo SQL Server...');
  const poolRst = await sql.connect(cfgRst);
  log('   OK\n');

  // Sucursales en Brilo (tabla en olComun)
  const briloSucs = await poolRst.request().query(`
    SELECT sucId, LTRIM(RTRIM(sucNombre)) AS sucNombre
    FROM olComun.dbo.Sucursales WITH (NOLOCK)
    ORDER BY sucNombre
  `);

  hr();
  console.log('  SUCURSALES EN BRILO (maeSucursales)');
  hr();
  console.log(`  ${'BriloID'.padStart(7)}  Nombre`);
  for (const s of briloSucs.recordset) {
    console.log(`  ${String(s.sucId).padStart(7)}  ${s.sucNombre}`);
  }

  // Ventas 01-11 mayo por sucursal/día — solo Platos Fuertes
  hr();
  console.log('  VENTAS 01-11 MAYO POR DÍA × SUCURSAL EN BRILO (Platos Fuertes)');
  hr();
  const briloVentas = await poolRst.request().query(`
    SELECT
      MCT.sucIdOrigenSync                              AS suc_id,
      MAX(LTRIM(RTRIM(SUC.sucNombre)))                 AS suc_nombre,
      CAST(MCT.mctrstFecHoraCerrada AT TIME ZONE 'UTC'
           AT TIME ZONE 'Central America Standard Time' AS DATE) AS fecha,
      COUNT(DISTINCT PRO.proId)                        AS platos_distintos,
      COUNT(DET.dctrstId)                              AS lineas_raw,
      ROUND(SUM(DET.dctrstCantidad * DET.dctrstPrecio), 2) AS total_usd
    FROM olRestaurante.dbo.maeCuentasRst MCT WITH (NOLOCK)
    INNER JOIN olRestaurante.dbo.detCuentasRst DET WITH (NOLOCK) ON DET.mctrstId = MCT.mctrstId
    INNER JOIN olComun.dbo.Productos           PRO WITH (NOLOCK) ON PRO.proId    = DET.proId
    LEFT  JOIN olComun.dbo.CategoriasProductos CPR WITH (NOLOCK) ON CPR.cprId   = PRO.cprId
    LEFT  JOIN olComun.dbo.Sucursales          SUC WITH (NOLOCK) ON SUC.sucId   = MCT.sucIdOrigenSync
    WHERE MCT.mctrstEliminado = 0
      AND DET.dctrstEliminado = 0
      AND DET.dctrstIdModificadorDe IS NULL
      AND CPR.cprNombre LIKE '%latos%uertes%'
      AND CAST(MCT.mctrstFecHoraCerrada AT TIME ZONE 'UTC'
               AT TIME ZONE 'Central America Standard Time' AS DATE)
          BETWEEN '${DESDE}' AND '${HASTA}'
    GROUP BY MCT.sucIdOrigenSync,
             CAST(MCT.mctrstFecHoraCerrada AT TIME ZONE 'UTC'
                  AT TIME ZONE 'Central America Standard Time' AS DATE)
    ORDER BY suc_nombre, fecha
  `);

  const sucResumen = {};
  let totalFilasEst = 0;
  let totalCabs     = 0;
  let granTotalUSD  = 0;
  let sucActual     = null;

  for (const r of briloVentas.recordset) {
    const key = `${r.suc_id}`;
    if (!sucResumen[key]) sucResumen[key] = { suc_id: r.suc_id, suc_nombre: r.suc_nombre, dias: 0, platos: 0, total: 0 };
    sucResumen[key].dias   += 1;
    sucResumen[key].platos += Number(r.platos_distintos);
    sucResumen[key].total  += Number(r.total_usd || 0);
    totalFilasEst += Number(r.platos_distintos);
    totalCabs     += 1;
    granTotalUSD  += Number(r.total_usd || 0);

    if (r.suc_nombre !== sucActual) {
      sucActual = r.suc_nombre;
      console.log(`\n  ▸ ${r.suc_nombre} (BriloID: ${r.suc_id})`);
      console.log(`    ${'Fecha'.padEnd(12)} ${'Platos únicos'.padStart(14)} ${'Líneas raw'.padStart(11)} ${'Total $'.padStart(12)}`);
    }
    console.log(`    ${d2s(r.fecha).padEnd(12)} ${String(r.platos_distintos).padStart(14)} ${String(r.lineas_raw).padStart(11)} ${('$' + Number(r.total_usd || 0).toFixed(2)).padStart(12)}`);
  }

  // Resumen consolidado por sucursal
  hr();
  console.log('  TOTALES POR SUCURSAL EN BRILO');
  hr();
  console.log(`  ${'BriloID'.padStart(7)}  ${'Sucursal'.padEnd(36)}  ${'Días'.padStart(5)}  ${'TotalPlatos'.padStart(12)}  ${'Venta $'.padStart(12)}`);
  for (const [, s] of Object.entries(sucResumen)) {
    console.log(`  ${String(s.suc_id).padStart(7)}  ${s.suc_nombre.slice(0, 35).padEnd(36)}  ${String(s.dias).padStart(5)}  ${fmt(s.platos).padStart(12)}  ${('$' + s.total.toFixed(2)).padStart(12)}`);
  }

  const sucCount = Object.keys(sucResumen).length;
  const estKB    = Math.round(totalFilasEst * 0.3);  // ~300 bytes/fila detalle

  hr('═');
  console.log('\n  ╔══════════════════════════════════════════════════════════╗');
  console.log(`  ║  RESUMEN DE MIGRACIÓN                                    ║`);
  console.log(`  ║  Período  : 01 Mayo → 11 Mayo 2026 (11 días)             ║`);
  console.log(`  ║  Sucursales con datos en Brilo : ${String(sucCount).padEnd(26)} ║`);
  console.log(`  ║  Cabeceras a insertar (suc×día): ${String(totalCabs).padEnd(26)} ║`);
  console.log(`  ║  Filas detalle (platos únicos) : ${fmt(totalFilasEst).padEnd(26)} ║`);
  console.log(`  ║  Venta total Platos Fuertes    : $${granTotalUSD.toFixed(2).padEnd(25)} ║`);
  console.log(`  ║  Peso estimado a insertar      : ~${String(estKB + 'KB').padEnd(25)} ║`);
  console.log(`  ╚══════════════════════════════════════════════════════════╝\n`);

  if (pgVentas.rows.length > 0) {
    console.log(`  ⚠️  HAY ${pgVentas.rows.length} REGISTROS ACTUALES QUE SERÁN BORRADOS ANTES DE IMPORTAR.`);
    console.log(`  ⚠️  Ejecuta: node database/import_ventas_mayo.js --apply\n`);
  } else {
    console.log(`  ✅ Sin datos actuales. Listo para importar.\n`);
    console.log(`  ▶  Ejecuta: node database/import_ventas_mayo.js --apply\n`);
  }

  await poolRst.close();
  coreClient.release();
  compClient.release();
  await pgCore.end();
  await pgComp.end();
  hr('═');
  log('Diagnóstico completado.');
  hr('═');
}

main().catch(e => {
  console.error('\nERROR FATAL:', e.message);
  if (e.message?.includes('ECONNREFUSED') || e.message?.includes('timeout')) {
    console.error('→ Verifica que la VPN esté activa (IP 10.0.4.20)');
  }
  process.exit(1);
});
