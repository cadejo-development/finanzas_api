/**
 * Sincroniza datos de kardex de Brilo a la tabla brilo_kardex de nuestra BD.
 *
 * Uso:
 *   node database/sync_brilo_kardex.js --sucursal=3 --hasta=2026-08-31 [--desde=2026-07-27] [--movs-hasta=2026-08-30]
 *
 * --hasta       = fecha del conteo físico (se almacena como fecha_hasta en BD, para lookup)
 * --desde       = inicio del período. Si se omite, se auto-detecta del último conteo_fisico.
 * --movs-hasta  = hasta qué día se incluyen movimientos de Brilo (FIN PERÍODO).
 *                 Si se omite, usa --hasta (mismo día que el conteo).
 *                 Usar --movs-hasta=HASTA-1 para sucursales cuyo período cierra el día anterior al conteo.
 */
require('dotenv').config();
const sql = require('mssql');
const { Client } = require('pg');

// ── Argumentos ────────────────────────────────────────────────────────────────
const args = Object.fromEntries(
  process.argv.slice(2)
    .filter(a => a.startsWith('--'))
    .map(a => { const [k, v] = a.slice(2).split('='); return [k, v]; })
);

const SUCURSAL_ID  = parseInt(args.sucursal);
const FECHA_CONTEO = args.hasta;   // fecha del conteo (= fecha_hasta en BD)
const DRY_RUN      = process.argv.includes('--dry-run');
const FILTER_COD   = args.codigo ?? null;  // --codigo=MR0901009 para ver solo ese producto
if (!SUCURSAL_ID || !FECHA_CONTEO) {
  console.error('Uso: node sync_brilo_kardex.js --sucursal=<id> --hasta=<YYYY-MM-DD> [--desde=<YYYY-MM-DD>] [--movs-hasta=<YYYY-MM-DD>] [--dry-run] [--codigo=<codigo>]');
  process.exit(1);
}
if (DRY_RUN) console.log('\n⚠️  MODO DRY RUN — no se escribira nada en la base de datos\n');

// --movs-hasta controla hasta qué día se leen movimientos de Brilo (FIN PERÍODO real).
// Por defecto = --hasta (mismo día del conteo). Para sucursales con FIN PERÍODO = día anterior, pasar --movs-hasta=FECHA-1.
const FECHA_MOVS_HASTA = args['movs-hasta'] ?? FECHA_CONTEO;

console.log(`Conteo: ${FECHA_CONTEO}  |  Movimientos hasta: ${FECHA_MOVS_HASTA}`);

// Mapeo sucursal_id → ubiId en Brilo
const SUCURSAL_BRILO_UBI = {
  1: 37, 2: 38, 3: 48, 4: 51,
  5: 52, 6: 56, 7: 57, 8: 58,
  9: 65, 10: 69, 11: 77, 12: 78,
  13: 79, 16: 76,
};

const UBI_ID = SUCURSAL_BRILO_UBI[SUCURSAL_ID];
if (!UBI_ID) {
  console.error(`No hay ubiId Brilo mapeado para sucursal_id=${SUCURSAL_ID}`);
  process.exit(1);
}

// ── Conexiones ────────────────────────────────────────────────────────────────
const sqlConfig = {
  server: process.env.DB_HOST_ORIGEN, port: parseInt(process.env.DB_PORT_ORIGEN),
  database: 'olInventario', user: process.env.DB_USERNAME_ORIGEN,
  password: process.env.DB_PASSWORD_ORIGEN,
  options: { encrypt: false, trustServerCertificate: true }, requestTimeout: 60000,
};

const pg = new Client({
  host: process.env.DB_HOST_COMPRAS, port: process.env.DB_PORT_COMPRAS || 5432,
  database: process.env.DB_DATABASE_COMPRAS, user: process.env.DB_USERNAME_COMPRAS,
  password: process.env.DB_PASSWORD_COMPRAS, ssl: { rejectUnauthorized: false },
});

async function main() {
  await pg.connect();

  // 1. Determinar fecha_desde
  let fechaDesde = args.desde ?? null;

  if (!fechaDesde) {
    // Auto-detectar desde el último conteo físico anterior en nuestra BD
    const { rows } = await pg.query(
      `SELECT MAX(DATE(fecha)) AS ultima FROM movimientos_inventario
       WHERE sucursal_id=$1 AND tipo='conteo_fisico' AND DATE(fecha) < $2`,
      [SUCURSAL_ID, FECHA_CONTEO]
    );
    fechaDesde = rows[0]?.ultima ?? null;
    if (fechaDesde) {
      console.log(`Auto-detectado fecha_desde = ${fechaDesde} (último conteo físico previo)`);
    } else {
      console.error('No se encontró conteo anterior. Pasa --desde=YYYY-MM-DD manualmente.');
      process.exit(1);
    }
  } else {
    console.log(`fecha_desde manual = ${fechaDesde}`);
  }

  // 2. Conectar a Brilo
  const pool = await sql.connect(sqlConfig);

  // 3. Snapshot mensual anterior a fecha_desde
  const snapRes = await pool.request()
    .input('ubiId', sql.Int, UBI_ID)
    .input('desde', sql.Date, fechaDesde)
    .query(`
      SELECT TOP 1 CONVERT(DATE, salhFecha) AS snap_fecha
      FROM SaldosHistoricos
      WHERE ubiId = @ubiId AND salhFecha < @desde
      ORDER BY salhFecha DESC
    `);
  if (!snapRes.recordset.length) {
    console.error('No hay snapshot histórico antes de', fechaDesde);
    process.exit(1);
  }
  const snapFecha = snapRes.recordset[0].snap_fecha.toISOString().slice(0, 10);
  console.log(`Snapshot base: ${snapFecha}  |  Período: ${fechaDesde} → ${FECHA_MOVS_HASTA}`);

  // 4. Saldos del snapshot
  console.log(`Leyendo snapshot ${snapFecha}...`);
  const snapSaldos = await pool.request()
    .input('ubiId', sql.Int, UBI_ID)
    .input('snapFecha', sql.Date, snapFecha)
    .query(`
      SELECT p.proCodigo, sh.salhSaldo
      FROM SaldosHistoricos sh
      JOIN olComun.dbo.Productos p ON p.proId = sh.proId
      WHERE sh.ubiId = @ubiId AND CONVERT(DATE, sh.salhFecha) = @snapFecha
    `);
  const snaps = {};
  for (const r of snapSaldos.recordset) snaps[r.proCodigo] = parseFloat(r.salhSaldo);
  console.log(`  ${Object.keys(snaps).length} productos en snapshot`);

  // 5. Movimientos desde snapshot hasta FECHA_MOVS_HASTA (día anterior al conteo)
  console.log(`Leyendo movimientos ${snapFecha} → ${FECHA_MOVS_HASTA}...`);
  const movRes = await pool.request()
    .input('ubiId',       sql.Int,  UBI_ID)
    .input('snapFecha',   sql.Date, snapFecha)
    .input('fechaDesde',  sql.Date, fechaDesde)
    .input('fechaHasta',  sql.Date, FECHA_MOVS_HASTA)
    .query(`
      SELECT
        p.proCodigo,
        -- Saldo_ini: todo antes del día de corte + ajustes de conteo DEL día de corte (tmoId 1,2,586,587)
        SUM(CASE WHEN t.tmoSigno= 1 AND (
              CAST(m.mmoFecha AS DATE) < @fechaDesde
              OR (CAST(m.mmoFecha AS DATE) = @fechaDesde AND m.tmoId IN (1,2,586,587))
            ) THEN d.dmoCantidad ELSE 0 END) AS e_antes,
        SUM(CASE WHEN t.tmoSigno=-1 AND (
              CAST(m.mmoFecha AS DATE) < @fechaDesde
              OR (CAST(m.mmoFecha AS DATE) = @fechaDesde AND m.tmoId IN (1,2,586,587))
            ) THEN d.dmoCantidad ELSE 0 END) AS s_antes,
        -- Entradas/Salidas: movimientos comerciales del día de corte en adelante (excluye ajustes de conteo del día)
        SUM(CASE WHEN t.tmoSigno= 1 AND (
              CAST(m.mmoFecha AS DATE) > @fechaDesde
              OR (CAST(m.mmoFecha AS DATE) = @fechaDesde AND m.tmoId NOT IN (1,2,586,587))
            ) THEN d.dmoCantidad ELSE 0 END) AS entradas,
        SUM(CASE WHEN t.tmoSigno=-1 AND (
              CAST(m.mmoFecha AS DATE) > @fechaDesde
              OR (CAST(m.mmoFecha AS DATE) = @fechaDesde AND m.tmoId NOT IN (1,2,586,587))
            ) THEN d.dmoCantidad ELSE 0 END) AS salidas
      FROM maeMovi m
      JOIN detMovi d ON d.mmoId = m.mmoId
      JOIN TiposMovi t ON t.tmoId = m.tmoId
      JOIN olComun.dbo.Productos p ON p.proId = d.proId
      WHERE m.mmoPosteado=1 AND m.mmoAnulado=0
        AND d.ubiId = @ubiId
        AND CAST(m.mmoFecha AS DATE) > @snapFecha
        AND CAST(m.mmoFecha AS DATE) <= @fechaHasta
      GROUP BY p.proCodigo
    `);
  console.log(`  ${movRes.recordset.length} productos con movimientos`);

  // 6. Calcular kardex por producto
  const kardex = {};
  for (const r of movRes.recordset) {
    const snapSaldo = snaps[r.proCodigo] ?? 0;
    const saldoIni  = snapSaldo + parseFloat(r.e_antes) - parseFloat(r.s_antes);
    const entradas  = parseFloat(r.entradas);
    const salidas   = parseFloat(r.salidas);
    kardex[r.proCodigo] = {
      saldo_ini: Math.round(saldoIni * 10000) / 10000,
      entradas:  Math.round(entradas  * 10000) / 10000,
      salidas:   Math.round(salidas   * 10000) / 10000,
      saldo_fin: Math.round((saldoIni + entradas - salidas) * 10000) / 10000,
    };
  }
  // Productos con snapshot pero sin movimientos en período
  for (const [codigo, saldo] of Object.entries(snaps)) {
    if (!kardex[codigo]) {
      kardex[codigo] = { saldo_ini: saldo, entradas: 0, salidas: 0, saldo_fin: saldo };
    }
  }

  await sql.close();

  // 7. Mostrar resultado y/o guardar en brilo_kardex
  const totalProds = Object.keys(kardex).length;
  const toShow     = FILTER_COD
    ? Object.entries(kardex).filter(([c]) => c === FILTER_COD)
    : Object.entries(kardex).slice(0, 20);

  console.log(`\nKardex calculado: ${totalProds} productos (fecha_hasta=${FECHA_CONTEO})`);
  console.log(`${'─'.repeat(80)}`);
  console.log(`${'Código'.padEnd(14)} ${'Saldo Ini'.padStart(12)} ${'Entradas'.padStart(12)} ${'Salidas'.padStart(12)} ${'Saldo Fin'.padStart(12)}`);
  console.log(`${'─'.repeat(80)}`);
  for (const [codigo, k] of toShow) {
    console.log(`${codigo.padEnd(14)} ${String(k.saldo_ini).padStart(12)} ${String(k.entradas).padStart(12)} ${String(k.salidas).padStart(12)} ${String(k.saldo_fin).padStart(12)}`);
  }
  if (!FILTER_COD && totalProds > 20) console.log(`  … y ${totalProds - 20} productos más`);
  console.log(`${'─'.repeat(80)}`);

  if (DRY_RUN) {
    await pg.end();
    console.log(`\n✓ DRY RUN completado — nada escrito en BD. Verifica los valores arriba.`);
    return;
  }

  console.log(`\nGuardando ${totalProds} registros...`);
  await pg.query(
    `DELETE FROM brilo_kardex WHERE sucursal_id=$1 AND fecha_desde=$2 AND fecha_hasta=$3`,
    [SUCURSAL_ID, fechaDesde, FECHA_CONTEO]
  );

  for (const [codigo, k] of Object.entries(kardex)) {
    await pg.query(
      `INSERT INTO brilo_kardex (sucursal_id, producto_codigo, fecha_desde, fecha_hasta, saldo_ini, entradas, salidas, saldo_fin)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8)`,
      [SUCURSAL_ID, codigo, fechaDesde, FECHA_CONTEO, k.saldo_ini, k.entradas, k.salidas, k.saldo_fin]
    );
  }

  await pg.end();
  console.log(`\n✓ Kardex sincronizado: período ${fechaDesde} → ${FECHA_MOVS_HASTA}, almacenado bajo fecha_hasta=${FECHA_CONTEO}`);
}

main().catch(e => { console.error(e.message); process.exit(1); });
