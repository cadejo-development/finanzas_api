/**
 * sync_materias_primas.js
 *
 * Sincroniza TODAS las materias primas (ingredientes de compras) desde SQL Server
 * (olComun) hacia PostgreSQL (compras_db.productos).
 *
 * Alcance:
 *   - Productos activos (proActivo=1) de categorías que son materias primas:
 *       'Carnes', 'Mariscos', 'Vegetales', 'Frutas', 'Lácteos', 'Abarrotes',
 *       'Bebidas', 'Licores', 'Madera / Carbón', 'Descartables', y cualquier
 *       otra que sea ingrediente en MXP activo.
 *   - Opera con UPSERT (ON CONFLICT DO UPDATE) — NO borra nada.
 *   - Actualiza: nombre, costo, precio, unidad, categoria_id.
 *   - Inserta productos nuevos que no existan en PG.
 *   - Las categorías de productos (tabla `categorias`) también son upsertadas.
 *
 * Uso: node sync_materias_primas.js [--dry-run]
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const sqlCfg = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 20000 },
};

const pgCfg = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'compras_db', user: 'cadejo_admin', password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false }, keepAlive: true,
  connectionTimeoutMillis: 30000, idleTimeoutMillis: 90000,
};

const DRY_RUN = process.argv.includes('--dry-run');
const BATCH   = 100;

// ── Normalización de unidades (igual que los otros scripts) ──────────────────
const UNIT_MAP = {
  'ONZAS':         'oz',
  'ONZAS FLUIDAS': 'oz fl',
  'UNIDAD':        'u',
  'LIBRA':         'lb',
  'LITRO':         'lt',
  'KILOGRAMO':     'kg',
  'KG':            'kg',
  'MILILITROS':    'ml',
  'MILILITROS ':   'ml',
  'BARRIL':        'barril',
  'BOTELLA 0.75 LT': 'botella',
  'BOTELLA 0.70 LT': 'botella',
  'BOTELLA 1.75 LT': 'botella 1.75lt',
  'BOTELLA':       'botella',
  'PORCION':       'porcion',
  'REBANADA':      'rebanada',
  'CAJA':          'caja',
  'PAQUETE':       'paquete',
  'GALON':         'galon',
  'BOLSA 2 Kg':    'bolsa 2kg',
  'BOLSA 1 KG':    'bolsa 1kg',
  'BOLSA 5 LIBRAS':'bolsa 5lb',
  'BOLSA 20 LB':   'bolsa 20lb',
};
const normalizeUnit = s =>
  !s ? 'u' : (UNIT_MAP[s.trim().toUpperCase()] ?? UNIT_MAP[s.trim()] ?? s.trim().toLowerCase().slice(0, 20));

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
  const text = `INSERT INTO ${table} (${columns.join(',')}) VALUES ${rowParts.join(',')} ${conflictSql}${returningSql ? ' ' + returningSql : ''}`;
  return { text, values: params };
}

// ── Query: todas las materias primas que son ingredientes en MXP ─────────────
// Incluye cualquier producto que aparezca como proIdMaterial en alguna receta activa.
// Esto cubre exactamente lo que es "materia prima" en el contexto del sistema.
const Q_MATERIAS_PRIMAS = `
SELECT DISTINCT
  PROM.proId       AS id_origen,
  PROM.proCodigo   AS codigo,
  PROM.proNombre   AS nombre,
  CPR.cprCodigo    AS cat_codigo,
  CPR.cprNombre    AS cat_nombre,
  UNI.uniNombre    AS unidad_nombre,
  ISNULL(PROM.proCosto, 0)  AS costo,
  ISNULL(PROM.proPrecio, 0) AS precio,
  PROM.proActivo   AS activo
FROM olComun.dbo.MaterialesXProducto MXP WITH(NOLOCK)
INNER JOIN olComun.dbo.Productos PROM WITH(NOLOCK)
  ON MXP.proIdMaterial = PROM.proId
LEFT JOIN olComun.dbo.CategoriasProductos CPR WITH(NOLOCK)
  ON PROM.cprId = CPR.cprId
LEFT JOIN olComun.dbo.Unidades UNI WITH(NOLOCK)
  ON UNI.uniId = PROM.uniId
WHERE MXP.mxprEliminado = 0
  AND MXP.mxprCantUnidad > 0
  AND PROM.proEliminado = 0
ORDER BY CPR.cprCodigo, PROM.proCodigo
`;

// ── Query: categorías de esas materias primas ────────────────────────────────
const Q_CATEGORIAS = `
SELECT DISTINCT
  CPR.cprCodigo AS codigo,
  CPR.cprNombre AS nombre
FROM olComun.dbo.MaterialesXProducto MXP WITH(NOLOCK)
INNER JOIN olComun.dbo.Productos PROM WITH(NOLOCK)
  ON MXP.proIdMaterial = PROM.proId AND PROM.proEliminado = 0
INNER JOIN olComun.dbo.CategoriasProductos CPR WITH(NOLOCK)
  ON PROM.cprId = CPR.cprId
WHERE MXP.mxprEliminado = 0
  AND MXP.mxprCantUnidad > 0
  AND CPR.cprCodigo IS NOT NULL
ORDER BY CPR.cprCodigo
`;

// ── MAIN ─────────────────────────────────────────────────────────────────────
async function main() {
  log('='.repeat(60));
  log('SYNC MATERIAS PRIMAS: SQL Server → PostgreSQL (upsert)');
  log(DRY_RUN ? '*** DRY-RUN — sin escritura ***' : '*** MODO REAL ***');
  log('='.repeat(60));

  log('\nConectando SQL Server...');
  const sqlPool = await sql.connect(sqlCfg);
  log('SQL Server OK');

  log('Conectando PostgreSQL (compras_db)...');
  const pool = new Pool(pgCfg);
  const pg   = await pool.connect();
  log('PostgreSQL OK\n');

  const now = new Date().toISOString();

  // ── 1. Cargar materias primas desde SS ───────────────────────────────────
  log('[1/3] Cargando materias primas desde SQL Server...');
  const mpRows = (await sqlPool.request().query(Q_MATERIAS_PRIMAS)).recordset;

  // Deduplicar por id_origen (por si hay duplicados en MXP)
  const mpMap = new Map();
  for (const r of mpRows) {
    if (!mpMap.has(r.id_origen)) mpMap.set(r.id_origen, r);
  }
  const mps = [...mpMap.values()];
  log(`      ${mps.length} materias primas únicas encontradas.`);

  // ── 2. Cargar categorías desde SS ────────────────────────────────────────
  log('\n[2/3] Sincronizando categorías de materias primas...');
  const catRows = (await sqlPool.request().query(Q_CATEGORIAS)).recordset;
  log(`      ${catRows.length} categorías.`);

  // Mapa cat_codigo → PG categoria.id
  const catKeyMap = {};

  if (!DRY_RUN) {
    // Asegurar que existe SIN-CAT
    await pg.query(`
      INSERT INTO categorias (key, nombre, orden, activo, aud_usuario, created_at, updated_at)
      VALUES ('SIN-CAT', 'Sin Categoría', 99, true, 'sync_mp', $1, $1)
      ON CONFLICT (key) DO UPDATE SET updated_at = EXCLUDED.updated_at
    `, [now]);

    const catCols = ['key', 'nombre', 'orden', 'activo', 'aud_usuario', 'created_at', 'updated_at'];
    const catConf = 'ON CONFLICT (key) DO UPDATE SET nombre=EXCLUDED.nombre, updated_at=EXCLUDED.updated_at';
    const catRet  = 'RETURNING id, key';

    for (let i = 0; i < catRows.length; i += BATCH) {
      const chunk = catRows.slice(i, i + BATCH);
      const rows  = chunk.map(c => [
        clean(c.codigo, 30).replace(/[^a-zA-Z0-9\-_]/g, '-'),
        clean(c.nombre, 80),
        0, true, 'sync_mp', now, now,
      ]);
      const q   = buildBatch('categorias', catCols, rows, catConf, catRet);
      const res = await pg.query(q.text, q.values);
      res.rows.forEach(r => { catKeyMap[r.key] = Number(r.id); });
    }

    // Recargar completo por si hay categorías previas
    const allCats = await pg.query('SELECT id, key FROM categorias');
    allCats.rows.forEach(r => { catKeyMap[r.key] = Number(r.id); });

    log(`      OK: ${catRows.length} categorías upsertadas.`);
  } else {
    catRows.forEach((c, i) => {
      const key = clean(c.codigo, 30).replace(/[^a-zA-Z0-9\-_]/g, '-');
      catKeyMap[key] = i + 1;
    });
    log('      (dry-run)');
  }

  // Función auxiliar: obtener categoria_id para un registro de MP
  const getCatId = (mp) => {
    if (!mp.cat_codigo) return catKeyMap['SIN-CAT'] ?? null;
    const key = clean(mp.cat_codigo, 30).replace(/[^a-zA-Z0-9\-_]/g, '-');
    return catKeyMap[key] ?? catKeyMap['SIN-CAT'] ?? null;
  };

  // ── 3. Upsert materias primas en productos ───────────────────────────────
  log('\n[3/3] Upsertando productos (materias primas) en PostgreSQL...');

  let inserted = 0, updated = 0, skipped = 0;

  if (!DRY_RUN) {
    const pCols = [
      'categoria_id', 'codigo', 'codigo_origen', 'nombre',
      'unidad', 'precio', 'costo', 'origen', 'activo',
      'aud_usuario', 'created_at', 'updated_at',
    ];
    // Actualizar nombre, costo, precio, unidad y categoria si ya existe (por codigo)
    // WHERE modificado_localmente = false → si el usuario editó el producto en el sistema,
    // el sync NO lo sobreescribe.
    const pConf = `ON CONFLICT (codigo) DO UPDATE SET
      nombre       = EXCLUDED.nombre,
      costo        = EXCLUDED.costo,
      precio       = EXCLUDED.precio,
      unidad       = EXCLUDED.unidad,
      categoria_id = EXCLUDED.categoria_id,
      activo       = EXCLUDED.activo,
      updated_at   = EXCLUDED.updated_at
      WHERE productos.modificado_localmente = false`;

    for (let i = 0; i < mps.length; i += BATCH) {
      const chunk = mps.slice(i, i + BATCH);
      const rows  = chunk.map(mp => [
        getCatId(mp),
        clean(mp.codigo, 30),
        clean(mp.codigo, 50),   // codigo_origen = codigo SS
        clean(mp.nombre, 150),
        normalizeUnit(mp.unidad_nombre),
        parseFloat(mp.precio) || 0,
        parseFloat(mp.costo)  || 0,
        'restaurante',
        mp.activo ? true : false,
        'sync_mp',
        now,
        now,
      ]);
      const q = buildBatch('productos', pCols, rows, pConf);
      await pg.query(q.text, q.values);

      if ((i / BATCH) % 5 === 4) log(`      ... ${i + BATCH} / ${mps.length} procesados`);
    }

    // Contar resultados
    const pgCount = await pg.query(
      "SELECT COUNT(*) AS total FROM productos WHERE aud_usuario = 'sync_mp'"
    );
    const totalSynced = parseInt(pgCount.rows[0].total, 10);
    log(`\n      OK: ${mps.length} materias primas sincronizadas (upsert).`);
    log(`      Productos con aud_usuario='sync_mp' en DB: ${totalSynced}`);

    // Mostrar resumen de costos actualizados (top 5 con mayor costo)
    const sample = await pg.query(`
      SELECT codigo, nombre, costo, unidad
      FROM productos
      WHERE costo > 0
      ORDER BY costo DESC
      LIMIT 5
    `);
    log('\n      Top 5 productos por costo actualizado:');
    sample.rows.forEach(r =>
      log(`        ${r.codigo.padEnd(15)} ${String(r.costo).padStart(8)} / ${r.unidad}  ${r.nombre.slice(0, 40)}`)
    );

  } else {
    // Dry-run: mostrar resumen
    const byCategory = {};
    mps.forEach(mp => {
      const cat = mp.cat_nombre || 'SIN-CAT';
      byCategory[cat] = (byCategory[cat] || 0) + 1;
    });
    log('\n      Distribución por categoría (dry-run):');
    Object.entries(byCategory)
      .sort((a, b) => b[1] - a[1])
      .forEach(([cat, count]) => log(`        ${String(count).padStart(4)}  ${cat}`));
    log(`\n      Total: ${mps.length} materias primas serían actualizadas.`);
  }

  // ── Cerrar conexiones ────────────────────────────────────────────────────
  pg.release();
  await pool.end();
  await sqlPool.close();

  log('\n' + '='.repeat(60));
  log('SYNC MATERIAS PRIMAS completado.');
  log('='.repeat(60));
}

main().catch(err => {
  console.error(`\n[ERROR] ${err.message}`);
  console.error(err.stack);
  process.exit(1);
});
