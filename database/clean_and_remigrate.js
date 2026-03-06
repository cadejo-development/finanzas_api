/**
 * clean_and_remigrate.js
 *
 * 1. Limpia las tablas de compras (respetando FK order)
 * 2. Reinicia las secuencias (IDs desde 1)
 * 3. Vuelve a correr la migración SQL Server → PostgreSQL
 *
 * Uso: node clean_and_remigrate.js
 */

const sql      = require('mssql');
const { Pool } = require('pg');

// ── Conexiones ────────────────────────────────────────────────────────────────
const sqlConfig = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

const pgConfig = {
  host: 'centerbeam.proxy.rlwy.net', port: 54991,
  database: 'railway', user: 'postgres',
  password: 'PeEZeoTayeiGpLohXdoxnJgECRyArmvw',
  ssl: { rejectUnauthorized: false },
  keepAlive: true,
  connectionTimeoutMillis: 30000,
  idleTimeoutMillis: 90000,
};

const BATCH = 50;

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
const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s => console.log(`[${ts()}] ${s}`);

function buildBatch(table, columns, rows, conflictSql, returningSql = '') {
  const params = [];
  const rowParts = rows.map(row => {
    const ph = row.map(v => { params.push(v); return `$${params.length}`; });
    return `(${ph.join(',')})`;
  });
  const text = `INSERT INTO ${table} (${columns.join(',')}) VALUES ${rowParts.join(',')} ${conflictSql} ${returningSql}`;
  return { text, values: params };
}

// ── Queries SQL Server (mismas que migrate.js) ────────────────────────────────
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
  log('LIMPIEZA + RE-MIGRACIÓN SQL Server → PostgreSQL');
  log('================================================');

  log('Conectando SQL Server...');
  const sqlPool = await sql.connect(sqlConfig);
  log('SQL Server OK');

  log('Conectando PostgreSQL...');
  const pool = new Pool(pgConfig);
  const pg   = await pool.connect();
  log('PostgreSQL OK');

  // ======================================================================
  // PASO 0: LIMPIEZA — eliminar datos y resetear secuencias
  // ======================================================================
  log('');
  log('[0/5] Limpiando tablas y reiniciando secuencias...');

  await pg.query('BEGIN');
  try {
    // Orden: hijos primero, luego padres
    await pg.query('DELETE FROM receta_ingredientes');
    log('      receta_ingredientes: borrada');
    await pg.query('DELETE FROM receta_sucursal');
    log('      receta_sucursal:     borrada');
    await pg.query('DELETE FROM recetas');
    log('      recetas:             borrada');
    await pg.query('DELETE FROM productos');
    log('      productos:           borrada');
    await pg.query('DELETE FROM categorias');
    log('      categorias:          borrada');

    // Resetear secuencias a 1
    for (const tabla of ['receta_ingredientes','receta_sucursal','recetas','productos','categorias']) {
      await pg.query(`SELECT setval(pg_get_serial_sequence('${tabla}', 'id'), 1, false)`);
    }
    log('      Secuencias reiniciadas a 1 ✓');

    await pg.query('COMMIT');
  } catch (e) {
    await pg.query('ROLLBACK');
    throw e;
  }

  const now = new Date().toISOString();

  // ======================================================================
  // 1. CATEGORIAS
  // ======================================================================
  log('');
  log('[1/5] Categorías...');
  const catRows = (await sqlPool.request().query(Q_CAT)).recordset;
  log(`      ${catRows.length} categorías desde SQL Server`);

  const catIdMap = {};
  const catCols  = ['key','nombre','orden','activo','aud_usuario','created_at','updated_at'];

  for (let i = 0; i < catRows.length; i += BATCH) {
    const chunk = catRows.slice(i, i + BATCH);
    const rows  = chunk.map(c => [
      clean(c.codigo, 30).replace(/[^a-zA-Z0-9\-_]/g, '-'),
      clean(c.nombre, 80), 0, true, 'migrate', now, now,
    ]);
    const q = buildBatch('categorias', catCols, rows,
      'ON CONFLICT (key) DO UPDATE SET nombre=EXCLUDED.nombre, updated_at=EXCLUDED.updated_at',
      'RETURNING id, key');
    const res = await pg.query(q.text, q.values);
    res.rows.forEach(r => {
      const orig = catRows.find(c =>
        clean(c.codigo, 30).replace(/[^a-zA-Z0-9\-_]/g, '-') === r.key);
      if (orig) catIdMap[orig.codigo] = r.id;
    });
  }

  // Fallback SIN-CAT
  await pg.query(`
    INSERT INTO categorias (key,nombre,orden,activo,aud_usuario,created_at,updated_at)
    VALUES ('SIN-CAT','Sin Categoria',99,true,'migrate',NOW(),NOW())
    ON CONFLICT (key) DO UPDATE SET updated_at=NOW()
    RETURNING id
  `);
  const allCats = await pg.query('SELECT id, key FROM categorias');
  allCats.rows.forEach(r => {
    const orig = catRows.find(c =>
      clean(c.codigo, 30).replace(/[^a-zA-Z0-9\-_]/g, '-') === r.key);
    if (orig) catIdMap[orig.codigo] = r.id;
  });
  const sinCatId = (await pg.query("SELECT id FROM categorias WHERE key='SIN-CAT'")).rows[0]?.id;
  log(`      ${Object.keys(catIdMap).length} categorías insertadas ✓`);

  // ======================================================================
  // 2. PRODUCTOS (ingredientes)
  // ======================================================================
  log('');
  log('[2/5] Productos (ingredientes)...');
  const ingrRows = (await sqlPool.request().query(Q_INGR)).recordset;
  log(`      ${ingrRows.length} productos desde SQL Server`);

  const prodIdMap  = {};
  const prodCols   = ['codigo','nombre','unidad','precio','costo','categoria_id','activo','codigo_origen','aud_usuario','created_at','updated_at'];

  let saltados = 0;
  for (let i = 0; i < ingrRows.length; i += BATCH) {
    const chunk = ingrRows.slice(i, i + BATCH);
    const rows  = [];
    for (const p of chunk) {
      const catId = catIdMap[p.cat_codigo] ?? sinCatId;
      if (!catId) { saltados++; continue; }
      rows.push([
        clean(p.codigo_origen, 30),
        clean(p.nombre),
        normalizeUnit(p.unidad_nombre),
        Number(p.precio ?? 0),
        Number(p.costo  ?? 0),
        catId,
        true,
        clean(p.codigo_origen, 50),
        'migrate', now, now,
      ]);
    }
    if (!rows.length) continue;
    const q = buildBatch('productos', prodCols, rows,
      'ON CONFLICT (codigo_origen) DO UPDATE SET nombre=EXCLUDED.nombre, precio=EXCLUDED.precio, costo=EXCLUDED.costo, updated_at=EXCLUDED.updated_at',
      'RETURNING id, codigo_origen');
    const res = await pg.query(q.text, q.values);
    res.rows.forEach(r => {
      const orig = ingrRows.find(p => clean(p.codigo_origen, 30) === r.codigo_origen);
      if (orig) prodIdMap[orig.id_origen] = r.id;
    });
  }
  log(`      ${Object.keys(prodIdMap).length} productos insertados ✓  (${saltados} saltados)`);

  // ======================================================================
  // 3. RECETAS (platos padre)
  // ======================================================================
  log('');
  log('[3/5] Recetas...');
  const recRows = (await sqlPool.request().query(Q_REC)).recordset;
  log(`      ${recRows.length} recetas desde SQL Server`);

  const recIdMap = {};
  const recCols  = ['nombre','tipo','platos_semana','activa','codigo_origen','aud_usuario','created_at','updated_at'];

  for (let i = 0; i < recRows.length; i += BATCH) {
    const chunk = recRows.slice(i, i + BATCH);
    const rows  = chunk.map(r => [
      clean(r.nombre),
      clean(r.tipo ?? '', 80),
      0,
      true,
      clean(r.codigo_origen, 50),
      'migrate', now, now,
    ]);
    const q = buildBatch('recetas', recCols, rows,
      'ON CONFLICT (codigo_origen) DO UPDATE SET nombre=EXCLUDED.nombre, tipo=EXCLUDED.tipo, updated_at=EXCLUDED.updated_at',
      'RETURNING id, codigo_origen');
    const res = await pg.query(q.text, q.values);
    res.rows.forEach(r => {
      const orig = recRows.find(p => clean(p.codigo_origen, 50) === r.codigo_origen);
      if (orig) recIdMap[orig.id_origen] = r.id;
    });
  }
  log(`      ${Object.keys(recIdMap).length} recetas insertadas ✓`);

  // ======================================================================
  // 4. RECETA_INGREDIENTES
  // ======================================================================
  log('');
  log('[4/5] Receta ingredientes...');
  const mxpRows = (await sqlPool.request().query(Q_MXP)).recordset;
  log(`      ${mxpRows.length} filas MXP desde SQL Server`);

  let ingInserted = 0, ingSkipped = 0;
  const ingCols = ['receta_id','producto_id','cantidad_por_plato','unidad','aud_usuario','created_at','updated_at'];

  for (let i = 0; i < mxpRows.length; i += BATCH) {
    const chunk = mxpRows.slice(i, i + BATCH);
    const rows  = [];
    for (const m of chunk) {
      const recId  = recIdMap[m.plato_id_origen];
      const prodId = prodIdMap[m.ingr_id_origen];
      if (!recId || !prodId) { ingSkipped++; continue; }
      rows.push([recId, prodId, Number(m.cantidad ?? 0), normalizeUnit(m.uni_nombre), 'migrate', now, now]);
    }
    if (!rows.length) continue;
    const q = buildBatch('receta_ingredientes', ingCols, rows,
      'ON CONFLICT (receta_id, producto_id) DO UPDATE SET cantidad_por_plato=EXCLUDED.cantidad_por_plato, updated_at=EXCLUDED.updated_at');
    await pg.query(q.text, q.values);
    ingInserted += rows.length;
  }
  log(`      ${ingInserted} ingredientes insertados ✓  (${ingSkipped} saltados)`);

  // ======================================================================
  // RESUMEN
  // ======================================================================
  log('');
  log('================================================');
  log('MIGRACIÓN COMPLETADA');
  log(`  Categorías : ${Object.keys(catIdMap).length}`);
  log(`  Productos  : ${Object.keys(prodIdMap).length}`);
  log(`  Recetas    : ${Object.keys(recIdMap).length}`);
  log(`  Ingredientes: ${ingInserted}`);
  log('================================================');

  pg.release();
  await pool.end();
  await sql.close();
}

main().catch(e => {
  console.error('ERROR FATAL:', e.message);
  process.exit(1);
});
