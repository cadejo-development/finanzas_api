/**
 * sync_kardex_aeropuertos.js
 *
 * Recalcula brilo_kardex para AE1 (suc=4), AE2 (suc=5) y Malcriadas (suc=16)
 * excluyendo movimientos de tipo AJUSTE y TRASLADO.
 *
 * Para estas sucursales los ajustes y traslados nocturnos de Enrique distorsionan
 * el saldo_fin reportado en Brilo vs el conteo físico real.
 *
 * Uso:
 *   node database/sync_kardex_aeropuertos.js --hasta=2026-08-31 [--dry-run] [--sucursal=4|5|16] [--codigo=MR090...]
 *
 *   --hasta       fecha del conteo (fecha_hasta en brilo_kardex). REQUERIDO.
 *   --desde       inicio del período. Si se omite, se auto-detecta del último conteo_fisico.
 *   --movs-hasta  hasta qué día se incluyen movimientos. Si se omite, usa --hasta.
 *   --sucursal    procesa solo esa sucursal (4, 5 ó 16). Si se omite, procesa las 3.
 *   --codigo      muestra solo ese producto en la salida (útil para debug).
 *   --dry-run     muestra comparación antes/después sin escribir en BD.
 */

require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const sql      = require('mssql');
const { Pool } = require('pg');

// ── Argumentos ────────────────────────────────────────────────────────────────
const rawArgs = process.argv.slice(2);
const DRY_RUN = rawArgs.includes('--dry-run');
const argMap  = Object.fromEntries(
  rawArgs.filter(a => a.startsWith('--') && a.includes('=')).map(a => {
    const [k, v] = a.slice(2).split('=');
    return [k, v];
  })
);

const FECHA_HASTA      = argMap['hasta'];
const FECHA_MOVS_HASTA = argMap['movs-hasta'] ?? FECHA_HASTA;
const FECHA_DESDE_ARG  = argMap['desde'] ?? null;
const SUCURSAL_ARG     = argMap['sucursal'] ? parseInt(argMap['sucursal']) : null;
const FILTER_COD       = argMap['codigo'] ?? null;

if (!FECHA_HASTA) {
  console.error('Error: --hasta=YYYY-MM-DD es requerido');
  console.error('Uso: node database/sync_kardex_aeropuertos.js --hasta=2026-08-31 [--dry-run] [--sucursal=4|5|16]');
  process.exit(1);
}

// Sucursales destino: AE1=4, AE2=5, Malcriadas=16
const SUCURSALES_TARGET = [4, 5, 16];
const SUCURSALES        = SUCURSAL_ARG ? [SUCURSAL_ARG] : SUCURSALES_TARGET;

// Validar que solo se procesen las 3 sucursales permitidas
for (const s of SUCURSALES) {
  if (!SUCURSALES_TARGET.includes(s)) {
    console.error(`Error: sucursal_id=${s} no es AE1 (4), AE2 (5) ni Malcriadas (16)`);
    process.exit(1);
  }
}

// Mapeo sucursal_id → ubiId en Brilo + nombre
const SUCURSAL_MAP = {
  4:  { ubiId: 51, nombre: 'REST. AEROPUERTO #1 (AE1)' },
  5:  { ubiId: 52, nombre: 'REST. AEROPUERTO #2 (AE2)' },
  16: { ubiId: 76, nombre: 'REST. MALCRIADAS AE2'      },
};

// ── Conexiones ────────────────────────────────────────────────────────────────
const SQL_CFG = {
  server:   process.env.DB_HOST_ORIGEN,
  port:     parseInt(process.env.DB_PORT_ORIGEN) || 2033,
  database: 'olInventario',
  user:     process.env.DB_USERNAME_ORIGEN,
  password: process.env.DB_PASSWORD_ORIGEN,
  options:  { trustServerCertificate: true, encrypt: false },
  requestTimeout: 120000,
};

const PG_CFG = {
  host:     process.env.DB_HOST_COMPRAS,
  port:     parseInt(process.env.DB_PORT_COMPRAS) || 5432,
  database: process.env.DB_DATABASE_COMPRAS,
  user:     process.env.DB_USERNAME_COMPRAS,
  password: process.env.DB_PASSWORD_COMPRAS,
  ssl: { rejectUnauthorized: false },
};

// ── Helpers ───────────────────────────────────────────────────────────────────
const fmt = (n) => n === null || n === undefined ? 'n/a' : String(Math.round(parseFloat(n) * 100) / 100).padStart(10);
const log = (s) => console.log(s);
const sep = (c = '─', n = 80) => log(c.repeat(n));

// ── Descubrir tmoIds de AJUSTE y TRASLADO ────────────────────────────────────
async function descubrirTiposExcluir(pool) {
  // Leer toda la tabla TiposMovi para descubrir la columna de descripción dinámicamente
  log('\n[1/5] Descubriendo tipos de movimiento en Brilo (TiposMovi)...');
  const allRes = await pool.request().query('SELECT * FROM TiposMovi');
  const allTmos = allRes.recordset;

  if (!allTmos.length) {
    console.error('  TiposMovi está vacía — verifica la conexión a Brilo');
    process.exit(1);
  }

  // Columnas disponibles
  const cols = Object.keys(allTmos[0]);
  log(`  Columnas: ${cols.join(', ')}`);

  // Encontrar la columna que contiene 'AJUSTE' o 'TRASLADO'
  let descCol = null;
  for (const col of cols) {
    if (col.toLowerCase() === 'tmoid') continue;
    const vals = allTmos.map(r => String(r[col] ?? '').toUpperCase().trim());
    if (vals.some(v => v === 'AJUSTE' || v === 'TRASLADO')) {
      descCol = col;
      break;
    }
  }

  if (!descCol) {
    // Fallback: buscar que CONTENGA las palabras
    for (const col of cols) {
      if (col.toLowerCase() === 'tmoid') continue;
      const vals = allTmos.map(r => String(r[col] ?? '').toUpperCase());
      if (vals.some(v => v.includes('AJUSTE') || v.includes('TRASLADO'))) {
        descCol = col;
        break;
      }
    }
  }

  if (!descCol) {
    log('  ⚠️  No se encontró columna con AJUSTE/TRASLADO. Valores de muestra:');
    allTmos.slice(0, 8).forEach(r => log('    ' + JSON.stringify(r)));
    console.error('\nError: No se puede identificar los tipos a excluir. Revisa los valores arriba.');
    process.exit(1);
  }

  log(`  Campo de descripción detectado: "${descCol}"`);

  // Encontrar los tmoIds para AJUSTE y TRASLADO
  const tiposExcluir = allTmos.filter(r => {
    const v = String(r[descCol] ?? '').toUpperCase().trim();
    return v === 'AJUSTE' || v === 'TRASLADO' || v.includes('AJUSTE') || v.includes('TRASLADO');
  });

  if (!tiposExcluir.length) {
    console.error(`  Error: No se encontraron tipos AJUSTE/TRASLADO en columna ${descCol}`);
    process.exit(1);
  }

  const tmoIds = tiposExcluir.map(r => r.tmoId);
  log(`  Tipos a EXCLUIR:`);
  tiposExcluir.forEach(r => log(`    tmoId=${r.tmoId}  →  ${r[descCol]}`));
  log(`  tmoIds: [${tmoIds.join(', ')}]`);

  return tmoIds;
}

// ── Sync de una sucursal ──────────────────────────────────────────────────────
async function procesarSucursal(pg, pool, sucursalId, tmoIdsExcluir, globalFechaDesde) {
  const { ubiId, nombre } = SUCURSAL_MAP[sucursalId];
  sep('═');
  log(`▸ ${nombre}  (suc_id=${sucursalId}, ubiId=${ubiId})`);
  sep('═');

  // ── Auto-detectar fecha_desde ─────────────────────────────────────────────
  let fechaDesde = globalFechaDesde;
  if (!fechaDesde) {
    const { rows } = await pg.query(
      `SELECT MAX(DATE(fecha)) AS ultima FROM movimientos_inventario
       WHERE sucursal_id=$1 AND tipo='conteo_fisico' AND DATE(fecha) < $2`,
      [sucursalId, FECHA_HASTA]
    );
    fechaDesde = rows[0]?.ultima ?? null;
    if (!fechaDesde) {
      log(`  ⚠️  No hay conteo físico anterior a ${FECHA_HASTA} — omitida`);
      return;
    }
    log(`  fecha_desde auto-detectada: ${fechaDesde} (último conteo_fisico previo)`);
  }

  log(`  Período: ${fechaDesde} → ${FECHA_MOVS_HASTA}  (fecha_hasta BD: ${FECHA_HASTA})`);

  // ── Snapshot histórico ────────────────────────────────────────────────────
  log(`\n  [a] Buscando snapshot anterior a ${fechaDesde}...`);
  const snapRes = await pool.request()
    .input('ubiId', sql.Int, UBI_ID_TEMP(ubiId))
    .input('desde', sql.Date, fechaDesde)
    .query(`
      SELECT TOP 1 CONVERT(DATE, salhFecha) AS snap_fecha
      FROM SaldosHistoricos
      WHERE ubiId = @ubiId AND salhFecha < @desde
      ORDER BY salhFecha DESC
    `);

  if (!snapRes.recordset.length) {
    log(`  ⚠️  No hay snapshot antes de ${fechaDesde} — omitida`);
    return;
  }
  const snapFecha = snapRes.recordset[0].snap_fecha.toISOString().slice(0, 10);
  log(`  Snapshot base: ${snapFecha}`);

  // Saldos del snapshot
  const snapSaldosRes = await pool.request()
    .input('ubiId',     sql.Int,  ubiId)
    .input('snapFecha', sql.Date, snapFecha)
    .query(`
      SELECT p.proCodigo, sh.salhSaldo
      FROM SaldosHistoricos sh
      JOIN olComun.dbo.Productos p ON p.proId = sh.proId
      WHERE sh.ubiId = @ubiId AND CONVERT(DATE, sh.salhFecha) = @snapFecha
    `);
  const snaps = {};
  for (const r of snapSaldosRes.recordset) snaps[r.proCodigo] = parseFloat(r.salhSaldo);
  log(`  Snapshot: ${Object.keys(snaps).length} productos`);

  // ── Movimientos (EXCLUYENDO AJUSTE y TRASLADO) ────────────────────────────
  log(`\n  [b] Leyendo movimientos ${snapFecha} → ${FECHA_MOVS_HASTA} (sin tmoIds [${tmoIdsExcluir.join(',')}])...`);
  const excluirSQL = tmoIdsExcluir.join(',');
  const movRes = await pool.request()
    .input('ubiId',      sql.Int,  ubiId)
    .input('snapFecha',  sql.Date, snapFecha)
    .input('fechaDesde', sql.Date, fechaDesde)
    .input('fechaHasta', sql.Date, FECHA_MOVS_HASTA)
    .query(`
      SELECT
        p.proCodigo,
        SUM(CASE WHEN t.tmoSigno= 1 AND (
              CAST(m.mmoFecha AS DATE) < @fechaDesde
              OR (CAST(m.mmoFecha AS DATE) = @fechaDesde AND m.tmoId IN (1,2,586,587))
            ) THEN d.dmoCantidad ELSE 0 END) AS e_antes,
        SUM(CASE WHEN t.tmoSigno=-1 AND (
              CAST(m.mmoFecha AS DATE) < @fechaDesde
              OR (CAST(m.mmoFecha AS DATE) = @fechaDesde AND m.tmoId IN (1,2,586,587))
            ) THEN d.dmoCantidad ELSE 0 END) AS s_antes,
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
        AND m.tmoId NOT IN (${excluirSQL})
      GROUP BY p.proCodigo
    `);
  log(`  Movimientos: ${movRes.recordset.length} productos con movs (AJUSTE/TRASLADO excluidos)`);

  // ── Calcular kardex ───────────────────────────────────────────────────────
  const kardexNuevo = {};
  for (const r of movRes.recordset) {
    const snapSaldo = snaps[r.proCodigo] ?? 0;
    const saldoIni  = snapSaldo + parseFloat(r.e_antes) - parseFloat(r.s_antes);
    const entradas  = parseFloat(r.entradas);
    const salidas   = parseFloat(r.salidas);
    kardexNuevo[r.proCodigo] = {
      saldo_ini: Math.round(saldoIni * 10000) / 10000,
      entradas:  Math.round(entradas  * 10000) / 10000,
      salidas:   Math.round(salidas   * 10000) / 10000,
      saldo_fin: Math.round((saldoIni + entradas - salidas) * 10000) / 10000,
    };
  }
  for (const [codigo, saldo] of Object.entries(snaps)) {
    if (!kardexNuevo[codigo]) {
      kardexNuevo[codigo] = { saldo_ini: saldo, entradas: 0, salidas: 0, saldo_fin: saldo };
    }
  }

  // ── Leer kardex actual de BD para comparar ────────────────────────────────
  log(`\n  [c] Leyendo kardex actual de brilo_kardex...`);
  const { rows: kardexActualRows } = await pg.query(
    `SELECT producto_codigo, saldo_ini, entradas, salidas, saldo_fin
     FROM brilo_kardex
     WHERE sucursal_id=$1 AND fecha_desde=$2 AND fecha_hasta=$3`,
    [sucursalId, fechaDesde, FECHA_HASTA]
  );
  const kardexActual = {};
  for (const r of kardexActualRows) kardexActual[r.producto_codigo] = r;
  log(`  BD actual: ${kardexActualRows.length} filas en brilo_kardex para este período`);

  // ── Mostrar comparación ───────────────────────────────────────────────────
  log(`\n  COMPARACIÓN (fecha_desde=${fechaDesde}, fecha_hasta=${FECHA_HASTA}):`);
  sep('─');
  log(`${'Código'.padEnd(14)} ${'Ant.Ini'.padStart(10)} ${'Ant.Fin'.padStart(10)} ${'Nvo.Ini'.padStart(10)} ${'Nvo.Ent'.padStart(10)} ${'Nvo.Sal'.padStart(10)} ${'Nvo.Fin'.padStart(10)} ${'CAMBIO'.padStart(10)}`);
  sep('─');

  let cambios = 0;
  const todosCodigos = new Set([...Object.keys(kardexNuevo), ...Object.keys(kardexActual)]);
  const filtradoCodigos = FILTER_COD
    ? [...todosCodigos].filter(c => c === FILTER_COD)
    : [...todosCodigos].sort();

  for (const codigo of filtradoCodigos) {
    const ant = kardexActual[codigo];
    const nvo = kardexNuevo[codigo];
    const antFin = ant ? parseFloat(ant.saldo_fin) : null;
    const nvoFin = nvo ? nvo.saldo_fin : null;
    const delta = (antFin !== null && nvoFin !== null) ? (nvoFin - antFin) : null;

    if (!FILTER_COD && delta === 0) continue; // En modo normal, solo mostrar los que cambian

    const cambioStr = delta !== null
      ? (delta > 0 ? `+${Math.round(delta * 100) / 100}` : String(Math.round(delta * 100) / 100))
      : '(nuevo)';

    if (delta !== 0) cambios++;

    log(
      `${codigo.padEnd(14)}` +
      `${fmt(ant?.saldo_ini)} ${fmt(antFin)}` +
      `  →  ` +
      `${fmt(nvo?.saldo_ini)} ${fmt(nvo?.entradas)} ${fmt(nvo?.salidas)} ${fmt(nvoFin)}` +
      `  ${cambioStr.padStart(8)}`
    );
  }
  sep('─');
  log(`  Productos con cambio: ${cambios}`);

  if (DRY_RUN) {
    log(`\n  ⚠️  DRY RUN — nada escrito en BD para ${nombre}`);
    return;
  }

  // ── Escribir en brilo_kardex ──────────────────────────────────────────────
  log(`\n  [d] Actualizando brilo_kardex...`);
  await pg.query(
    `DELETE FROM brilo_kardex WHERE sucursal_id=$1 AND fecha_desde=$2 AND fecha_hasta=$3`,
    [sucursalId, fechaDesde, FECHA_HASTA]
  );

  let escritos = 0;
  for (const [codigo, k] of Object.entries(kardexNuevo)) {
    await pg.query(
      `INSERT INTO brilo_kardex (sucursal_id, producto_codigo, fecha_desde, fecha_hasta, saldo_ini, entradas, salidas, saldo_fin)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8)`,
      [sucursalId, codigo, fechaDesde, FECHA_HASTA, k.saldo_ini, k.entradas, k.salidas, k.saldo_fin]
    );
    escritos++;
  }
  log(`  ✓ ${escritos} filas guardadas en brilo_kardex`);
}

// Workaround para pasar ubiId como parámetro en query de snapshot
function UBI_ID_TEMP(ubiId) { return ubiId; }

// ── Main ──────────────────────────────────────────────────────────────────────
(async () => {
  sep('█');
  log(`  SYNC KARDEX AEROPUERTOS (SIN AJUSTE/TRASLADO)${DRY_RUN ? '  [DRY RUN]' : ''}`);
  log(`  Sucursales     : ${SUCURSALES.join(', ')} (${SUCURSALES.map(s => SUCURSAL_MAP[s].nombre).join(' | ')})`);
  log(`  fecha_hasta    : ${FECHA_HASTA}`);
  log(`  movs_hasta     : ${FECHA_MOVS_HASTA}`);
  log(`  fecha_desde    : ${FECHA_DESDE_ARG ?? '(auto-detectar)'}`);
  log(`  filtro código  : ${FILTER_COD ?? 'todos'}`);
  sep('█');

  if (DRY_RUN) log('\n⚠️  MODO DRY RUN — no se escribirá nada\n');

  const pg   = new Pool(PG_CFG);
  const pool = await sql.connect(SQL_CFG);

  try {
    // Descubrir tmoIds a excluir una sola vez (aplica a todas las sucursales)
    const tmoIdsExcluir = await descubrirTiposExcluir(pool);

    for (const sucursalId of SUCURSALES) {
      await procesarSucursal(pg, pool, sucursalId, tmoIdsExcluir, FECHA_DESDE_ARG);
    }

    sep('═');
    log(`\n${DRY_RUN ? '  DRY RUN completado.' : '  Sync completado.'} Sucursales: ${SUCURSALES.join(', ')}`);
    sep('═');

  } finally {
    await sql.close();
    await pg.end();
  }
})().catch(e => {
  console.error('\n❌ Error fatal:', e.message);
  process.exit(1);
});
