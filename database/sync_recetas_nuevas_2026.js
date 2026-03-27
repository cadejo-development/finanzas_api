/**
 * sync_recetas_nuevas_2026.js
 *
 * Importa recetas creadas en 2026 (código CCCC26MMNN) que no existen aún en RDS.
 * No borra nada — es aditivo. Se puede correr cuantas veces sea necesario.
 *
 * Uso: node sync_recetas_nuevas_2026.js [--dry-run]
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
const BATCH   = 50;
const UNIT_MAP = {
  'ONZAS': 'oz', 'ONZAS FLUIDAS': 'oz fl', 'UNIDAD': 'u',
  'LIBRA': 'lb', 'LITRO': 'lt', 'KILOGRAMO': 'kg', 'KG': 'kg',
  'BARRIL': 'barril', 'BOTELLA 0.75 LT': 'botella', 'BOTELLA 0.70 LT': 'botella',
  'PORCION': 'porcion', 'REBANADA': 'rebanada', 'CAJA': 'caja',
  'PAQUETE': 'paquete', 'GALON': 'galon',
  'BOLSA 2 Kg': 'bolsa 2kg', 'BOLSA 1 KG': 'bolsa 1kg',
};
const normalizeUnit = s => !s ? 'u' : (UNIT_MAP[s.trim().toUpperCase()] ?? s.trim().toLowerCase().slice(0, 20));
const clean = (s, max) => !s ? '' : String(s).trim().replace(/\s+/g, ' ').slice(0, max);
const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s => console.log(`[${ts()}] ${s}`);

async function main() {
  log('================================================');
  log('SYNC RECETAS NUEVAS 2026 → PostgreSQL (aditivo)');
  log(DRY_RUN ? '*** DRY RUN ***' : '*** MODO REAL ***');
  log('================================================');

  const sqlPool = await sql.connect(sqlCfg);
  log('SQL Server OK');
  const pool = new Pool(pgCfg);
  const pg   = await pool.connect();
  log('PostgreSQL OK');

  const now = new Date().toISOString();

  // ── Cargar contexto de PG ─────────────────────────────────────────────────
  const recatRows = (await pg.query('SELECT id, nombre FROM receta_categorias WHERE activa=true')).rows;
  const recatMap  = {}; // lower(nombre) → id
  recatRows.forEach(r => { recatMap[r.nombre.trim().toLowerCase()] = Number(r.id); });

  const existentes = new Set(
    (await pg.query('SELECT codigo_origen FROM recetas WHERE codigo_origen IS NOT NULL')).rows.map(r => r.codigo_origen)
  );

  const prodRows = (await pg.query('SELECT id, codigo FROM productos WHERE activo=true')).rows;
  const prodMap  = {}; // codigo → pg id
  prodRows.forEach(r => { prodMap[r.codigo] = r.id; });

  const catPgRows = (await pg.query('SELECT id, key FROM categorias')).rows;
  const catKeyMap = {};
  catPgRows.forEach(r => { catKeyMap[r.key] = Number(r.id); });
  const fallbackCatId = catPgRows.find(r => r.key === 'SIN-CAT')?.id ?? catPgRows[0]?.id;

  // ── 1. Recetas 2026 con BOM en SQL Server ─────────────────────────────────
  log('\n[1/3] Detectando recetas 2026 en SQL Server...');
  const recReq = sqlPool.request();
  recatRows.forEach((rc, i) => recReq.input(`rc${i}`, sql.VarChar, rc.nombre));
  const rcPh = recatRows.map((_, i) => `@rc${i}`).join(',');

  const allSS = (await recReq.query(`
    SELECT DISTINCT
      p.proId        AS id_origen,
      p.proCodigo    AS codigo,
      p.proNombre    AS nombre,
      cpr.cprNombre  AS tipo,
      ISNULL(p.proPrecio, 0) AS precio
    FROM olComun.dbo.Productos p WITH(NOLOCK)
    INNER JOIN olComun.dbo.CategoriasProductos cpr WITH(NOLOCK) ON p.cprId = cpr.cprId
    INNER JOIN olComun.dbo.MaterialesXProducto mx WITH(NOLOCK)
      ON mx.proId = p.proId AND mx.mxprEliminado = 0 AND mx.mxprCantUnidad > 0
    WHERE p.proActivo = 1
      AND LEN(p.proCodigo) = 10
      AND SUBSTRING(p.proCodigo, 5, 2) = '26'
      AND SUBSTRING(p.proCodigo, 7, 2) BETWEEN '01' AND '12'
      AND cpr.cprNombre NOT LIKE '%Sub-Receta%'
      AND cpr.cprNombre IN (${rcPh})
  `)).recordset;

  const nuevas = allSS.filter(r => !existentes.has(clean(r.codigo, 50)));
  log(`  Total 2026 con BOM en SS: ${allSS.length}`);
  log(`  Ya en RDS:                ${allSS.length - nuevas.length}`);
  log(`  Nuevas a importar:        ${nuevas.length}`);

  if (nuevas.length === 0) {
    log('\nNada que importar.');
    pg.release(); await pool.end(); await sqlPool.close(); return;
  }

  if (DRY_RUN) {
    log('\nEjemplos:');
    nuevas.slice(0, 15).forEach(r => log(`  ${r.codigo} - ${r.nombre} (${r.tipo})`));
    log('\nDRY-RUN OK. Corre sin --dry-run para migrar.');
    pg.release(); await pool.end(); await sqlPool.close(); return;
  }

  // ── 2. Insertar recetas nuevas ─────────────────────────────────────────────
  log('\n[2/3] Insertando recetas...');
  const nuevaRecetaMap = {}; // codigo → pg id
  let recOk = 0;

  for (let i = 0; i < nuevas.length; i += BATCH) {
    const chunk = nuevas.slice(i, i + BATCH);
    const params = [], parts = [];
    chunk.forEach(r => {
      const tipo  = clean(r.tipo, 80) || 'General';
      const catId = recatMap[tipo.trim().toLowerCase()] ?? null;
      const vals  = [
        clean(r.nombre, 150), clean(r.codigo, 50), tipo, catId,
        parseFloat(r.precio) || 0, 0, true, 'sync_nuevas_2026', now, now,
      ];
      const ph = vals.map(v => { params.push(v); return `$${params.length}`; });
      parts.push(`(${ph.join(',')})`);
    });
    const res = await pg.query(
      `INSERT INTO recetas (nombre,codigo_origen,tipo,categoria_id,precio,platos_semana,activa,aud_usuario,created_at,updated_at)
       VALUES ${parts.join(',')} ON CONFLICT (codigo_origen) DO NOTHING RETURNING id, codigo_origen`,
      params
    );
    res.rows.forEach(row => { nuevaRecetaMap[row.codigo_origen] = row.id; });
    recOk += res.rows.length;
  }
  log(`  Recetas insertadas: ${recOk}`);

  // ── 3. Migrar ingredientes ─────────────────────────────────────────────────
  log('\n[3/3] Migrando ingredientes...');
  const insertedNuevas = nuevas.filter(r => nuevaRecetaMap[clean(r.codigo, 50)]);
  let ingrOk = 0, ingrSkip = 0;

  // Traer todos los ingredientes de las recetas nuevas desde SS
  const allIngr = [];
  for (let ci = 0; ci < insertedNuevas.length; ci += 200) {
    const chunk = insertedNuevas.slice(ci, ci + 200);
    const req2  = sqlPool.request();
    chunk.forEach((r, i) => req2.input(`ni${i}`, sql.Int, r.id_origen));
    const niPh = chunk.map((_, i) => `@ni${i}`).join(',');
    const rows = (await req2.query(`
      SELECT
        mx.proId         AS plato_id_origen,
        mx.proIdMaterial AS ingr_id_origen,
        ingr.proCodigo   AS ingr_codigo,
        ingr.proNombre   AS ingr_nombre,
        cpr.cprCodigo    AS ingr_cat_codigo,
        SUM(mx.mxprCantUnidad)   AS cantidad,
        MAX(ISNULL(uni.uniNombre,'u')) AS uni_nombre
      FROM olComun.dbo.MaterialesXProducto mx WITH(NOLOCK)
      INNER JOIN olComun.dbo.Productos ingr WITH(NOLOCK) ON ingr.proId = mx.proIdMaterial
      LEFT  JOIN olComun.dbo.CategoriasProductos cpr WITH(NOLOCK) ON ingr.cprId = cpr.cprId
      LEFT  JOIN olComun.dbo.Unidades uni WITH(NOLOCK) ON uni.uniId = mx.uniId
      WHERE mx.mxprEliminado = 0 AND mx.mxprCantUnidad > 0 AND mx.proId IN (${niPh})
      GROUP BY mx.proId, mx.proIdMaterial, ingr.proCodigo, ingr.proNombre, cpr.cprCodigo
    `)).recordset;
    allIngr.push(...rows);
  }
  log(`  Líneas de ingredientes: ${allIngr.length}`);

  // Insertar productos faltantes
  const missingCodigos = [...new Set(allIngr.map(r => r.ingr_codigo).filter(c => c && !prodMap[c]))];
  if (missingCodigos.length > 0) {
    log(`  Productos faltantes: ${missingCodigos.length}. Sincronizando...`);
    for (let ci = 0; ci < missingCodigos.length; ci += 100) {
      const chunk = missingCodigos.slice(ci, ci + 100);
      const req3  = sqlPool.request();
      chunk.forEach((c, i) => req3.input(`mc${i}`, sql.VarChar, c));
      const ph4 = chunk.map((_, i) => `@mc${i}`).join(',');
      const mpData = (await req3.query(`
        SELECT p.proCodigo AS codigo, p.proNombre AS nombre,
               ISNULL(p.proCosto,0) AS costo, ISNULL(p.proPrecio,0) AS precio,
               cpr.cprCodigo AS cat_codigo, uni.uniNombre AS unidad_nombre
        FROM olComun.dbo.Productos p WITH(NOLOCK)
        LEFT JOIN olComun.dbo.CategoriasProductos cpr WITH(NOLOCK) ON p.cprId = cpr.cprId
        LEFT JOIN olComun.dbo.Unidades uni WITH(NOLOCK) ON uni.uniId = p.uniId
        WHERE p.proCodigo IN (${ph4})
      `)).recordset;
      if (!mpData.length) continue;
      const params = [], parts = [];
      mpData.forEach(r => {
        const cod    = clean(r.codigo, 30);
        const catKey = r.cat_codigo ? clean(r.cat_codigo, 30).replace(/[^a-zA-Z0-9\-_]/g, '-') : null;
        const catId  = (catKey && catKeyMap[catKey]) ? catKeyMap[catKey] : fallbackCatId;
        const origen = cod.toUpperCase().startsWith('CP') ? 'centro_produccion' : 'restaurante';
        const vals   = [catId, cod, clean(r.codigo, 50), clean(r.nombre, 150),
                        normalizeUnit(r.unidad_nombre), parseFloat(r.precio)||0, parseFloat(r.costo)||0,
                        origen, true, 'sync_nuevas_2026', now, now];
        const ph = vals.map(v => { params.push(v); return `$${params.length}`; });
        parts.push(`(${ph.join(',')})`);
      });
      const res = await pg.query(
        `INSERT INTO productos (categoria_id,codigo,codigo_origen,nombre,unidad,precio,costo,origen,activo,aud_usuario,created_at,updated_at)
         VALUES ${parts.join(',')}
         ON CONFLICT (codigo) DO UPDATE SET nombre=EXCLUDED.nombre, costo=EXCLUDED.costo, updated_at=EXCLUDED.updated_at
         RETURNING id, codigo`,
        params
      );
      res.rows.forEach(r => { prodMap[r.codigo] = r.id; });
    }
  }

  // Insertar ingredientes
  const idOrigenMap = {}; // id_origen → codigo
  insertedNuevas.forEach(r => { idOrigenMap[r.id_origen] = clean(r.codigo, 50); });

  for (const ingr of allIngr) {
    const recPgId  = nuevaRecetaMap[idOrigenMap[ingr.plato_id_origen]];
    const prodPgId = prodMap[ingr.ingr_codigo];
    if (!recPgId || !prodPgId) { ingrSkip++; continue; }
    await pg.query(
      `INSERT INTO receta_ingredientes (receta_id,producto_id,cantidad_por_plato,unidad,aud_usuario,created_at,updated_at)
       VALUES ($1,$2,$3,$4,'sync_nuevas_2026',$5,$5) ON CONFLICT (receta_id,producto_id) DO NOTHING`,
      [recPgId, prodPgId, parseFloat(ingr.cantidad) || 0, normalizeUnit(ingr.uni_nombre), now]
    );
    ingrOk++;
  }
  log(`  Ingredientes: ${ingrOk} insertados, ${ingrSkip} saltados`);

  log('\n================================================');
  log('RESUMEN:');
  log(`  Recetas 2026 detectadas:  ${allSS.length}`);
  log(`  Recetas nuevas insertadas:${recOk}`);
  log(`  Ingredientes insertados:  ${ingrOk}`);
  log(`  Ingredientes saltados:    ${ingrSkip}`);
  log('\n✓ Completado.');
  log('================================================');

  pg.release(); await pool.end(); await sqlPool.close();
}

main().catch(err => {
  console.error('\n❌ ERROR:', err.message);
  console.error(err.stack);
  process.exit(1);
});
