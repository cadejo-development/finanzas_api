/**
 * migrate.js  ─  SQL Server (olcomun) → PostgreSQL (compras DB Railway)
 *
 * Uso:  node migrate.js [--dry-run]
 *
 * Migra: categorias, productos (ingredientes), recetas, receta_ingredientes
 * Usa inserts por lote (batch=50) para evitar timeout en Railway.
 */

const sql      = require('mssql');
const { Pool } = require('pg');

// ── SQL Server ────────────────────────────────────────────────────────────────
const sqlConfig = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

// ── PostgreSQL compras ────────────────────────────────────────────────────────
const pgConfig = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'compras_db', user: 'cadejo_admin',
  password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
  keepAlive: true,
  connectionTimeoutMillis: 30000,
  idleTimeoutMillis: 90000,
};

const DRY_RUN = process.argv.includes('--dry-run');
const BATCH   = 50;

// ── Utilidades ────────────────────────────────────────────────────────────────
const UNIT_MAP = {
  'ONZAS': 'oz', 'ONZAS FLUIDAS': 'oz fl', 'UNIDAD': 'u',
  'LIBRA': 'lb', 'LITRO': 'lt', 'KILOGRAMO': 'kg', 'KG': 'kg',
  'BARRIL': 'barril', 'BOTELLA 0.75 LT': 'botella', 'BOTELLA 0.70 LT': 'botella',
  'PORCION': 'porcion', 'REBANADA': 'rebanada', 'CAJA': 'caja',
  'PAQUETE': 'paquete', 'GALON': 'galon',
  'BOLSA 2 Kg': 'bolsa 2kg', 'BOLSA 1 KG': 'bolsa 1kg',
};
const normalizeUnit = s =>
  !s ? 'u' : (UNIT_MAP[s.trim().toUpperCase()] ?? s.trim().toLowerCase().slice(0, 20));
const clean = (s, max = 150) =>
  !s ? '' : String(s).trim().replace(/\s+/g, ' ').slice(0, max);
const ts    = () => new Date().toTimeString().slice(0, 8);
const log   = s => console.log(`[${ts()}] ${s}`);

/**
 * buildBatch: genera texto SQL y array de parámetros para INSERT multi-fila.
 * Devuelve { text, values }.
 */
function buildBatch(table, columns, rows, conflictSql, returningSql = '') {
  const params = [];
  const rowParts = rows.map(row => {
    const ph = row.map(v => { params.push(v); return `$${params.length}`; });
    return `(${ph.join(',')})`;
  });
  const text = `INSERT INTO ${table} (${columns.join(',')}) VALUES ${rowParts.join(',')} ${conflictSql} ${returningSql}`;
  return { text, values: params };
}

// ── Queries SQL Server ────────────────────────────────────────────────────────
const Q_CAT = `
SELECT DISTINCT CPR.cprCodigo AS codigo, CPR.cprNombre AS nombre
FROM olComun.dbo.MaterialesXProducto MXP WITH (NOLOCK)
INNER JOIN olComun.dbo.Productos PROM WITH (NOLOCK) ON MXP.proIdMaterial = PROM.proId
LEFT  JOIN olComun.dbo.CategoriasProductos CPR WITH (NOLOCK) ON PROM.cprId = CPR.cprId
WHERE MXP.mxprEliminado = 0 AND CPR.cprCodigo IS NOT NULL
UNION
SELECT DISTINCT CPR2.cprCodigo, CPR2.cprNombre
FROM olComun.dbo.MaterialesXProducto MXP2 WITH (NOLOCK)
INNER JOIN olComun.dbo.Productos PRO2 WITH (NOLOCK) ON MXP2.proId = PRO2.proId
INNER JOIN olComun.dbo.CategoriasProductos CPR2 WITH (NOLOCK) ON PRO2.cprId = CPR2.cprId
WHERE MXP2.mxprEliminado = 0 AND CPR2.cprCodigo IS NOT NULL
ORDER BY codigo`;

const Q_INGR = `
SELECT DISTINCT
  PROM.proId AS id_origen, PROM.proCodigo AS codigo_origen, PROM.proNombre AS nombre,
  CPR.cprCodigo AS cat_codigo, UNI.uniNombre AS unidad_nombre,
  PROM.proCosto AS costo, PROM.proPrecio AS precio
FROM olComun.dbo.MaterialesXProducto MXP WITH (NOLOCK)
INNER JOIN olComun.dbo.Productos PROM WITH (NOLOCK) ON MXP.proIdMaterial = PROM.proId
LEFT  JOIN olComun.dbo.CategoriasProductos CPR WITH (NOLOCK) ON PROM.cprId = CPR.cprId
LEFT  JOIN olComun.dbo.Unidades UNI WITH (NOLOCK) ON UNI.uniId = PROM.uniId
WHERE MXP.mxprEliminado = 0`;

const Q_REC = `
SELECT DISTINCT
  PRO.proId AS id_origen, PRO.proCodigo AS codigo_origen, PRO.proNombre AS nombre,
  CPR.cprNombre AS tipo
FROM olComun.dbo.MaterialesXProducto MXP WITH (NOLOCK)
INNER JOIN olComun.dbo.Productos PRO WITH (NOLOCK) ON MXP.proId = PRO.proId
LEFT  JOIN olComun.dbo.CategoriasProductos CPR WITH (NOLOCK) ON PRO.cprId = CPR.cprId
WHERE MXP.mxprEliminado = 0 AND PRO.proActivo = 1
ORDER BY CPR.cprNombre, PRO.proCodigo`;

const Q_MXP = `
SELECT
  MXP.proId         AS plato_id_origen,
  MXP.proIdMaterial AS ingr_id_origen,
  MXP.mxprCantUnidad AS cantidad,
  UNI.uniNombre      AS uni_nombre
FROM olComun.dbo.MaterialesXProducto MXP WITH (NOLOCK)
INNER JOIN olComun.dbo.Productos PRO  WITH (NOLOCK) ON MXP.proId         = PRO.proId
INNER JOIN olComun.dbo.Productos PROM WITH (NOLOCK) ON MXP.proIdMaterial = PROM.proId
LEFT  JOIN olComun.dbo.Unidades  UNI  WITH (NOLOCK) ON UNI.uniId         = MXP.uniId
WHERE MXP.mxprEliminado = 0 AND MXP.mxprCantUnidad > 0
  AND PRO.proActivo = 1 AND UNI.uniCodigo IS NOT NULL`;

// ─────────────────────────────────────────────────────────────────────────────
async function main() {
  log('================================================');
  log('MIGRACIÓN SQL Server → PostgreSQL compras');
  log(DRY_RUN ? '*** DRY RUN — sin escritura ***' : '*** MODO REAL ***');
  log('================================================');

  log('Conectando SQL Server...');
  const sqlPool = await sql.connect(sqlConfig);
  log('SQL Server OK');

  log('Conectando PostgreSQL...');
  const pool = new Pool(pgConfig);
  const pg   = await pool.connect();
  log('PostgreSQL OK');

  const now = new Date().toISOString();

  // ======================================================================
  // 1. CATEGORIAS
  // ======================================================================
  log('');
  log('[1/4] Categorías...');
  const catRows = (await sqlPool.request().query(Q_CAT)).recordset;
  log(`      ${catRows.length} categorías.`);

  const catIdMap = {};

  if (!DRY_RUN) {
    const catCols = ['key','nombre','orden','activo','aud_usuario','created_at','updated_at'];
    const catConf = 'ON CONFLICT (key) DO UPDATE SET nombre=EXCLUDED.nombre, updated_at=EXCLUDED.updated_at';

    for (let i = 0; i < catRows.length; i += BATCH) {
      const chunk = catRows.slice(i, i + BATCH);
      const rows  = chunk.map(c => [
        clean(c.codigo, 30).replace(/[^a-zA-Z0-9\-_]/g, '-'),
        clean(c.nombre, 80), 0, true, 'migrate', now, now,
      ]);
      const q = buildBatch('categorias', catCols, rows, catConf);
      await pg.query(q.text, q.values);
    }

    // Fallback
    await pg.query(`
      INSERT INTO categorias (key,nombre,orden,activo,aud_usuario,created_at,updated_at)
      VALUES ('SIN-CAT','Sin Categoria',99,true,'migrate',NOW(),NOW())
      ON CONFLICT (key) DO UPDATE SET updated_at=NOW()
    `);

    // Build full map cprCodigo → pg id
    const all = await pg.query('SELECT id, key FROM categorias');
    all.rows.forEach(r => {
      const orig = catRows.find(c =>
        clean(c.codigo, 30).replace(/[^a-zA-Z0-9\-_]/g, '-') === r.key
      );
      if (orig) catIdMap[orig.codigo] = r.id;
    });
    const fbRow = all.rows.find(r => r.key === 'SIN-CAT');
    catIdMap['__fallback__'] = fbRow?.id ?? null;

    log(`      OK: ${catRows.length} upsertadas.`);
  } else {
    catRows.forEach((c, i) => { catIdMap[c.codigo] = i + 1; });
    catIdMap['__fallback__'] = 0;
    log('      (dry-run)');
  }

  // ======================================================================
  // 2. PRODUCTOS
  // ======================================================================
  log('');
  log('[2/4] Productos (ingredientes)...');
  const ingrRaw = (await sqlPool.request().query(Q_INGR)).recordset;
  const ingrMap = {};
  ingrRaw.forEach(r => { ingrMap[r.id_origen] = r; });
  const ingrs = Object.values(ingrMap);
  log(`      ${ingrs.length} únicos.`);

  const prodIdMap = {};

  if (!DRY_RUN) {
    const pCols = ['categoria_id','codigo','codigo_origen','nombre','unidad','precio','costo','origen','activo','aud_usuario','created_at','updated_at'];
    const pConf = `ON CONFLICT (codigo) DO UPDATE SET
      nombre=EXCLUDED.nombre, codigo_origen=EXCLUDED.codigo_origen,
      unidad=EXCLUDED.unidad, precio=EXCLUDED.precio, costo=EXCLUDED.costo,
      origen=EXCLUDED.origen,
      categoria_id=EXCLUDED.categoria_id, updated_at=EXCLUDED.updated_at`;

    for (let i = 0; i < ingrs.length; i += BATCH) {
      const chunk = ingrs.slice(i, i + BATCH);
      const rows  = chunk.map(r => {
        const cod    = clean(r.codigo_origen, 30);
        const origen = cod.toUpperCase().startsWith('CP') ? 'centro_produccion' : 'restaurante';
        return [
          catIdMap[r.cat_codigo] ?? catIdMap['__fallback__'],
          cod,
          clean(r.codigo_origen, 50),
          clean(r.nombre, 150),
          normalizeUnit(r.unidad_nombre),
          parseFloat(r.precio) || 0,
          parseFloat(r.costo)  || 0,
          origen,
          true, 'migrate', now, now,
        ];
      });
      const q = buildBatch('productos', pCols, rows, pConf);
      await pg.query(q.text, q.values);
      if ((i + BATCH) % 500 === 0) log(`      ... ${i + BATCH} productos procesados`);
    }

    // Mapa id_origen → pg id via codigo_origen
    const pgP = await pg.query('SELECT id, codigo_origen FROM productos WHERE codigo_origen IS NOT NULL');
    const coOMap = {};
    pgP.rows.forEach(r => { coOMap[r.codigo_origen] = r.id; });
    ingrs.forEach(r => { prodIdMap[r.id_origen] = coOMap[clean(r.codigo_origen, 50)]; });

    log(`      OK: ${ingrs.length} productos upsertados.`);
  } else {
    ingrs.forEach((r, i) => { prodIdMap[r.id_origen] = i + 1; });
    log('      (dry-run)');
  }

  // ======================================================================
  // 3. RECETAS
  // ======================================================================
  log('');
  log('[3/4] Recetas (platos con ingredientes)...');
  const recRows = (await sqlPool.request().query(Q_REC)).recordset;
  log(`      ${recRows.length} recetas.`);

  const recIdMap = {};

  if (!DRY_RUN) {
    const rCols = ['nombre','codigo_origen','tipo','platos_semana','activa','aud_usuario','created_at','updated_at'];
    const rConf = `ON CONFLICT (codigo_origen) DO UPDATE SET
      nombre=EXCLUDED.nombre, tipo=EXCLUDED.tipo, updated_at=EXCLUDED.updated_at`;

    for (let i = 0; i < recRows.length; i += BATCH) {
      const chunk = recRows.slice(i, i + BATCH);
      const rows  = chunk.map(r => [
        clean(r.nombre, 150),
        clean(r.codigo_origen, 50),
        clean(r.tipo, 80) || 'General',
        0, true, 'migrate', now, now,
      ]);
      const q = buildBatch('recetas', rCols, rows, rConf);
      await pg.query(q.text, q.values);
      if ((i + BATCH) % 500 === 0) log(`      ... ${i + BATCH} recetas procesadas`);
    }

    const pgR = await pg.query('SELECT id, codigo_origen FROM recetas WHERE codigo_origen IS NOT NULL');
    const rCoMap = {};
    pgR.rows.forEach(r => { rCoMap[r.codigo_origen] = r.id; });
    recRows.forEach(r => { recIdMap[r.id_origen] = rCoMap[clean(r.codigo_origen, 50)]; });

    log(`      OK: ${recRows.length} recetas upsertadas.`);
  } else {
    recRows.forEach((r, i) => { recIdMap[r.id_origen] = i + 1; });
    log('      (dry-run)');
  }

  // ======================================================================
  // 4. INGREDIENTES DE RECETA
  // ======================================================================
  log('');
  log('[4/4] Ingredientes de receta...');
  const mxpRows = (await sqlPool.request().query(Q_MXP)).recordset;
  log(`      ${mxpRows.length} líneas encontradas.`);

  let riOk = 0, riSkip = 0;

  if (!DRY_RUN) {
    // Borrar ingredientes anteriores de las recetas migradas
    const recIds = Object.values(recIdMap).filter(Boolean);
    if (recIds.length) {
      // En lotes para no superar límites de parámetros
      for (let i = 0; i < recIds.length; i += 500) {
        const chunk = recIds.slice(i, i + 500);
        await pg.query(`DELETE FROM receta_ingredientes WHERE receta_id = ANY($1::int[])`, [chunk]);
      }
    }

    const riCols = ['receta_id','producto_id','cantidad_por_plato','unidad','aud_usuario','created_at','updated_at'];
    const validRows = [];
    for (const mxp of mxpRows) {
      const rid = recIdMap[mxp.plato_id_origen];
      const pid = prodIdMap[mxp.ingr_id_origen];
      if (!rid || !pid) { riSkip++; continue; }
      validRows.push([rid, pid, parseFloat(mxp.cantidad) || 0, normalizeUnit(mxp.uni_nombre), 'migrate', now, now]);
      riOk++;
    }

    for (let i = 0; i < validRows.length; i += BATCH) {
      const chunk = validRows.slice(i, i + BATCH);
      const q = buildBatch('receta_ingredientes', riCols, chunk, '');
      await pg.query(q.text, q.values);
      if ((i + BATCH) % 1000 === 0) log(`      ... ${i + BATCH} ingredientes`);
    }

    log(`      OK: ${riOk} insertados, ${riSkip} saltados.`);
  } else {
    mxpRows.forEach(r => {
      recIdMap[r.plato_id_origen] && prodIdMap[r.ingr_id_origen] ? riOk++ : riSkip++;
    });
    log(`      (dry-run) ${riOk} OK, ${riSkip} sin mapping.`);
  }

  // ── Resumen ─────────────────────────────────────────────────────────────────
  log('');
  log('================================================');
  log('RESUMEN:');
  log(`  Categorías:          ${catRows.length}`);
  log(`  Productos:           ${ingrs.length}`);
  log(`  Recetas:             ${recRows.length}`);
  log(`  Ingredientes receta: ${riOk}`);
  if (riSkip > 0) log(`  ADVERTENCIA saltados: ${riSkip}`);
  log(DRY_RUN ? '\nDRY-RUN OK. Corre sin --dry-run para migrar.' : '\n✓ Migración completada.');
  log('================================================');

  pg.release();
  await pool.end();
  await sqlPool.close();
}

main().catch(err => {
  console.error('\n❌ ERROR:', err.message, err.stack);
  process.exit(1);
});
