/**
 * sync_recetas_sucursales.js
 * SQL Server (olRestaurante + olComun) → PostgreSQL (core_db + compras_db)
 *
 * Fases:
 *  0. Sync sucursales restaurante → core_db
 *  1. Limpiar compras_db
 *  2. Añadir columna precio a recetas si no existe
 *  3. Sync categorias (de ingredientes usados en recetas de menú)
 *  4. Sync productos / ingredientes
 *  5. Sync recetas (solo platos en menú activo con receta)
 *  6. Sync receta_ingredientes (BOM)
 *  7. Sync receta_sucursal (qué áreas tienen cada receta)
 *
 * Uso: node sync_recetas_sucursales.js [--dry-run]
 */

const sql      = require('mssql');
const { Pool } = require('pg');

// ── Conexiones ────────────────────────────────────────────────────────────────
const sqlCfg = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 20000 },
};

const RDS_BASE = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432, user: 'cadejo_admin', password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false }, keepAlive: true,
  connectionTimeoutMillis: 30000, idleTimeoutMillis: 90000,
};

const pgCoreCfg    = { ...RDS_BASE, database: 'core_db'    };
const pgComprasCfg = { ...RDS_BASE, database: 'compras_db' };

// ── Mapeo AreasRst → Sucursal (SQL Server sucId) ─────────────────────────────
// arerstId (AreasRst) → sucId (olComun.Sucursales) → mismo id en core_db.sucursales
const AREA_TO_SUC = {
  1:  3,   // ZONA ROSA      → RES - ZONA ROSA
  16: 6,   // LA LIBERTAD    → RES - LA LIBERTAD
  17: 7,   // AEROPUERTO 1   → RES - AEROPUERTO-1
  18: 8,   // AEROPUERTO 2   → RES - AEROPUERTO-2
  22: 10,  // PLAZA VENECIA  → RES - PASEO VENECIA
  23: 11,  // SANTA ELENA    → RES - SANTA ELENA
  25: 12,  // HUIZUCAR       → RES - HUIZUCAR
  26: 13,  // OPICO          → RES - OPICO
  28: 16,  // MALCRIADAS AE  → RES - MALCRIADAS AE2
  30: 19,  // CASA GUIROLA   → RES - CASA GUIROLA
};
const ACTIVE_AREA_IDS = Object.keys(AREA_TO_SUC).map(Number);
const ACTIVE_SUC_IDS  = [...new Set(Object.values(AREA_TO_SUC))]; // [3,6,7,8,10,11,12,13,16,19]
const AREA_IN         = ACTIVE_AREA_IDS.join(',');

// ── Utilidades ────────────────────────────────────────────────────────────────
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

// ── Sub-query reutilizable: productos que están en algún área activa del menú ──
const Q_PLATOS_EN_MENU = `
  SELECT DISTINCT BTNRST.proId
  FROM olRestaurante.dbo.BotonesRst BTNRST WITH(NOLOCK)
  INNER JOIN olRestaurante.dbo.detMenusRst DMNRST WITH(NOLOCK)
    ON BTNRST.btnrstid = DMNRST.btnrstId AND DMNRST.dmnrstEliminado = 0
  INNER JOIN olRestaurante.dbo.maeMenusRst MMNRST WITH(NOLOCK)
    ON DMNRST.mmnrstId = MMNRST.mmnrstId AND MMNRST.mmnrstEliminado = 0
  INNER JOIN olRestaurante.dbo.AreasRst ARERST WITH(NOLOCK)
    ON MMNRST.arerstId = ARERST.arerstId AND ARERST.arerstEliminado = 0
  WHERE BTNRST.btnrstEliminado = 0
    AND ARERST.arerstActiva = 1
    AND ARERST.arerstNombre NOT LIKE 'ELIMINADO%'
    AND ARERST.arerstId IN (${AREA_IN})`;

// ── Queries principales ───────────────────────────────────────────────────────

// 0. Sucursales restaurante activas
const Q_SUCURSALES = `
  SELECT sucId AS id, sucCodigo AS codigo, sucNombre AS nombre
  FROM olComun.dbo.Sucursales WITH(NOLOCK)
  WHERE sucId IN (${ACTIVE_SUC_IDS.join(',')}) AND sucActiva = 1
  ORDER BY sucId`;

// 3. Categorías de ingredientes usados en recetas de menú
const Q_CAT = `
  SELECT DISTINCT CPR.cprCodigo AS codigo, CPR.cprNombre AS nombre
  FROM olComun.dbo.MaterialesXProducto MXP WITH(NOLOCK)
  INNER JOIN olComun.dbo.Productos PRO  WITH(NOLOCK) ON MXP.proId = PRO.proId AND PRO.proActivo = 1
  INNER JOIN olComun.dbo.Productos PROM WITH(NOLOCK) ON MXP.proIdMaterial = PROM.proId
  LEFT  JOIN olComun.dbo.CategoriasProductos CPR WITH(NOLOCK) ON PROM.cprId = CPR.cprId
  WHERE MXP.mxprEliminado = 0 AND MXP.mxprCantUnidad > 0
    AND CPR.cprCodigo IS NOT NULL
    AND PRO.proId IN (${Q_PLATOS_EN_MENU})
  UNION
  SELECT DISTINCT CPR2.cprCodigo, CPR2.cprNombre
  FROM olComun.dbo.MaterialesXProducto MXP2 WITH(NOLOCK)
  INNER JOIN olComun.dbo.Productos PRO2  WITH(NOLOCK) ON MXP2.proId = PRO2.proId AND PRO2.proActivo = 1
  INNER JOIN olComun.dbo.CategoriasProductos CPR2 WITH(NOLOCK) ON PRO2.cprId = CPR2.cprId
  WHERE MXP2.mxprEliminado = 0 AND MXP2.mxprCantUnidad > 0
    AND CPR2.cprCodigo IS NOT NULL
    AND PRO2.proId IN (${Q_PLATOS_EN_MENU})
  ORDER BY codigo`;

// 4. Productos / ingredientes usados en recetas de menú
const Q_INGR = `
  SELECT DISTINCT
    PROM.proId    AS id_origen,
    PROM.proCodigo AS codigo_origen,
    PROM.proNombre AS nombre,
    CPR.cprCodigo  AS cat_codigo,
    UNI.uniNombre  AS unidad_nombre,
    PROM.proCosto  AS costo,
    PROM.proPrecio AS precio
  FROM olComun.dbo.MaterialesXProducto MXP WITH(NOLOCK)
  INNER JOIN olComun.dbo.Productos PRO  WITH(NOLOCK) ON MXP.proId = PRO.proId AND PRO.proActivo = 1
  INNER JOIN olComun.dbo.Productos PROM WITH(NOLOCK) ON MXP.proIdMaterial = PROM.proId
  LEFT  JOIN olComun.dbo.CategoriasProductos CPR WITH(NOLOCK) ON PROM.cprId = CPR.cprId
  LEFT  JOIN olComun.dbo.Unidades UNI WITH(NOLOCK) ON UNI.uniId = PROM.uniId
  WHERE MXP.mxprEliminado = 0 AND MXP.mxprCantUnidad > 0
    AND PRO.proId IN (${Q_PLATOS_EN_MENU})`;

// 5. Recetas (platos en menú con BOM activo)
const Q_REC = `
  SELECT DISTINCT
    PRO.proId      AS id_origen,
    PRO.proCodigo  AS codigo_origen,
    PRO.proNombre  AS nombre,
    CPR.cprNombre  AS tipo,
    ISNULL(PRO.proPrecio, 0) AS precio
  FROM olComun.dbo.MaterialesXProducto MXP WITH(NOLOCK)
  INNER JOIN olComun.dbo.Productos PRO WITH(NOLOCK) ON MXP.proId = PRO.proId AND PRO.proActivo = 1
  LEFT  JOIN olComun.dbo.CategoriasProductos CPR WITH(NOLOCK) ON PRO.cprId = CPR.cprId
  WHERE MXP.mxprEliminado = 0 AND MXP.mxprCantUnidad > 0
    AND PRO.proId IN (${Q_PLATOS_EN_MENU})
  ORDER BY CPR.cprNombre, PRO.proCodigo`;

// 6. Ingredientes de receta (BOM) para platos en menú
const Q_MXP = `
  SELECT
    MXP.proId         AS plato_id_origen,
    MXP.proIdMaterial AS ingr_id_origen,
    SUM(MXP.mxprCantUnidad) AS cantidad,
    MAX(UNI.uniNombre)      AS uni_nombre
  FROM olComun.dbo.MaterialesXProducto MXP WITH(NOLOCK)
  INNER JOIN olComun.dbo.Productos PRO  WITH(NOLOCK) ON MXP.proId         = PRO.proId  AND PRO.proActivo = 1
  INNER JOIN olComun.dbo.Productos PROM WITH(NOLOCK) ON MXP.proIdMaterial = PROM.proId
  LEFT  JOIN olComun.dbo.Unidades  UNI  WITH(NOLOCK) ON UNI.uniId         = MXP.uniId
  WHERE MXP.mxprEliminado = 0 AND MXP.mxprCantUnidad > 0
    AND UNI.uniCodigo IS NOT NULL
    AND PRO.proId IN (${Q_PLATOS_EN_MENU})
  GROUP BY MXP.proId, MXP.proIdMaterial`;

// 8. Modificadores de recetas (ModificadoresRst + ModificadoresXProdRst)
// Jerarquía: padre = mdfrstIdPadre IS NULL / 0, hijo = mdfrstIdPadre = padre.mdfrstId
const Q_MODS = `
  SELECT
    MXP.proId                            AS receta_id_origen,
    MDR.mdfrstId                         AS grupo_id_origen,
    MDR.mdfrstCodigo                     AS grupo_codigo,
    MDR.mdfrstNombre                     AS grupo_nombre,
    MDD.mdfrstNombre                     AS opcion_nombre,
    PMOD.proId                           AS mod_prod_id_origen,
    ISNULL(MDD.mdfrstCantidadProducto, 0) AS cantidad,
    UNI.uniNombre                        AS uni_nombre
  FROM olRestaurante.dbo.ModificadoresXProdRst MXP WITH(NOLOCK)
  INNER JOIN olRestaurante.dbo.ModificadoresRst MDR WITH(NOLOCK)
    ON MXP.mdfrstId = MDR.mdfrstId
    AND (MDR.mdfrstIdPadre IS NULL OR MDR.mdfrstIdPadre = 0)
    AND MDR.mdfrstEliminado = 0
  INNER JOIN olRestaurante.dbo.ModificadoresRst MDD WITH(NOLOCK)
    ON MDD.mdfrstIdPadre = MDR.mdfrstId
    AND MDD.mdfrstEliminado = 0
  LEFT  JOIN olComun.dbo.Productos PMOD WITH(NOLOCK)
    ON MDD.proId = PMOD.proId
  LEFT  JOIN olComun.dbo.Unidades UNI WITH(NOLOCK)
    ON UNI.uniId = PMOD.uniId
  WHERE MXP.mxprstEliminado = 0
    AND MXP.proId IN (${Q_PLATOS_EN_MENU})
  ORDER BY MXP.proId, MDR.mdfrstId, MDD.mdfrstNombre`;

// 7. Relación receta ↔ área (para receta_sucursal)
const Q_REC_AREA = `
  SELECT DISTINCT
    PRO.proId      AS receta_id_origen,
    ARERST.arerstId AS area_id
  FROM olRestaurante.dbo.BotonesRst BTNRST WITH(NOLOCK)
  INNER JOIN olRestaurante.dbo.detMenusRst DMNRST WITH(NOLOCK)
    ON BTNRST.btnrstid = DMNRST.btnrstId AND DMNRST.dmnrstEliminado = 0
  INNER JOIN olRestaurante.dbo.maeMenusRst MMNRST WITH(NOLOCK)
    ON DMNRST.mmnrstId = MMNRST.mmnrstId AND MMNRST.mmnrstEliminado = 0
  INNER JOIN olRestaurante.dbo.AreasRst ARERST WITH(NOLOCK)
    ON MMNRST.arerstId = ARERST.arerstId AND ARERST.arerstEliminado = 0
  INNER JOIN olComun.dbo.Productos PRO WITH(NOLOCK)
    ON BTNRST.proId = PRO.proId AND PRO.proEliminado = 0 AND PRO.proActivo = 1
  INNER JOIN olComun.dbo.MaterialesXProducto MXP WITH(NOLOCK)
    ON PRO.proId = MXP.proId AND MXP.mxprEliminado = 0 AND MXP.mxprCantUnidad > 0
  WHERE BTNRST.btnrstEliminado = 0
    AND ARERST.arerstActiva = 1
    AND ARERST.arerstNombre NOT LIKE 'ELIMINADO%'
    AND ARERST.arerstId IN (${AREA_IN})`;

// ── MAIN ─────────────────────────────────────────────────────────────────────
async function main() {
  log('='.repeat(60));
  log('SYNC SQL Server → PostgreSQL  (recetas + sucursales)');
  log(DRY_RUN ? '*** DRY-RUN — sin escritura ***' : '*** MODO REAL ***');
  log('='.repeat(60));

  log('Conectando SQL Server...');
  const sqlPool = await sql.connect(sqlCfg);
  log('SQL Server OK');

  log('Conectando core_db...');
  const pgCore = new Pool(pgCoreCfg);
  const core   = await pgCore.connect();
  log('core_db OK');

  log('Conectando compras_db...');
  const pgComp = new Pool(pgComprasCfg);
  const comp   = await pgComp.connect();
  log('compras_db OK');

  const now = new Date().toISOString();

  // ============================================================
  // FASE 0 — Sync sucursales → core_db
  // ============================================================
  log('\n[0/8] Sucursales restaurante → core_db...');
  const sucRows = (await sqlPool.request().query(Q_SUCURSALES)).recordset;
  log(`      ${sucRows.length} sucursales encontradas.`);

  // Obtener tipo_sucursal_id "operativa"
  let tipoOpId = null;
  const tipoRes = await core.query("SELECT id FROM tipos_sucursal WHERE codigo='operativa' LIMIT 1");
  tipoOpId = tipoRes.rows[0]?.id ?? null;
  if (!tipoOpId) log('      AVISO: tipos_sucursal vacío, tipo_sucursal_id quedará NULL');

  if (!DRY_RUN) {
    for (const s of sucRows) {
      await core.query(`
        INSERT INTO sucursales (id, nombre, codigo, tipo_sucursal_id, aud_usuario, created_at, updated_at)
        OVERRIDING SYSTEM VALUE
        VALUES ($1, $2, $3, $4, 'sync', NOW(), NOW())
        ON CONFLICT (id) DO UPDATE SET
          nombre = EXCLUDED.nombre,
          updated_at = NOW()
      `, [s.id, clean(s.nombre, 100), clean(s.codigo, 30), tipoOpId]);
    }
    // Resetear secuencia para que los próximos auto-id no colisionen
    await core.query("SELECT setval('sucursales_id_seq', GREATEST((SELECT MAX(id) FROM sucursales), 1))");
    log(`      OK: ${sucRows.length} sucursales upsertadas.`);
  } else {
    sucRows.forEach(s => log(`      [dry] ${s.id} — ${s.nombre}`));
  }

  // ============================================================
  // FASE 1 — Limpiar compras_db
  // ============================================================
  log('\n[1/8] Limpiando compras_db...');
  if (!DRY_RUN) {
    await comp.query('DELETE FROM receta_sucursal');
    await comp.query('DELETE FROM receta_ingredientes');
    await comp.query('DELETE FROM recetas');
    await comp.query('DELETE FROM productos');
    await comp.query('DELETE FROM categorias');
    log('      OK: tablas vaciadas.');
  } else {
    log('      (dry-run)');
  }

  // ============================================================
  // FASE 2 — Asegurar columna precio en recetas
  // ============================================================
  log('\n[2/8] Columna precio en recetas...');
  if (!DRY_RUN) {
    await comp.query(`
      ALTER TABLE recetas ADD COLUMN IF NOT EXISTS precio NUMERIC(10,2) DEFAULT 0
    `);
    log('      OK.');
  }

  // ============================================================
  // FASE 3 — Categorías
  // ============================================================
  log('\n[3/8] Categorías...');
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
        clean(c.nombre, 80), 0, true, 'sync', now, now,
      ]);
      await comp.query(buildBatch('categorias', catCols, rows, catConf).text,
                       buildBatch('categorias', catCols, rows, catConf).values);
    }
    await comp.query(`
      INSERT INTO categorias (key,nombre,orden,activo,aud_usuario,created_at,updated_at)
      VALUES ('SIN-CAT','Sin Categoría',99,true,'sync',NOW(),NOW())
      ON CONFLICT (key) DO UPDATE SET updated_at=NOW()
    `);
    const all = await comp.query('SELECT id, key FROM categorias');
    all.rows.forEach(r => {
      const orig = catRows.find(c =>
        clean(c.codigo, 30).replace(/[^a-zA-Z0-9\-_]/g, '-') === r.key
      );
      if (orig) catIdMap[orig.codigo] = r.id;
    });
    catIdMap['__fb__'] = all.rows.find(r => r.key === 'SIN-CAT')?.id ?? null;
    log(`      OK: ${catRows.length} upsertadas.`);
  } else {
    catRows.forEach((c, i) => { catIdMap[c.codigo] = i + 1; });
    catIdMap['__fb__'] = 0;
    log('      (dry-run)');
  }

  // ============================================================
  // FASE 4 — Productos (ingredientes)
  // ============================================================
  log('\n[4/8] Productos / ingredientes...');
  const ingrRaw = (await sqlPool.request().query(Q_INGR)).recordset;
  // Deduplicar por id_origen
  const ingrMap = {};
  ingrRaw.forEach(r => { ingrMap[r.id_origen] = r; });
  const ingrs = Object.values(ingrMap);
  log(`      ${ingrs.length} únicos.`);
  const prodIdMap = {};

  if (!DRY_RUN) {
    const pCols = ['categoria_id','codigo','codigo_origen','nombre','unidad','precio','costo','activo','aud_usuario','created_at','updated_at'];
    const pConf = `ON CONFLICT (codigo) DO UPDATE SET
      nombre=EXCLUDED.nombre, codigo_origen=EXCLUDED.codigo_origen,
      unidad=EXCLUDED.unidad, precio=EXCLUDED.precio, costo=EXCLUDED.costo,
      categoria_id=EXCLUDED.categoria_id, updated_at=EXCLUDED.updated_at`;
    for (let i = 0; i < ingrs.length; i += BATCH) {
      const chunk = ingrs.slice(i, i + BATCH);
      const rows  = chunk.map(r => [
        catIdMap[r.cat_codigo] ?? catIdMap['__fb__'],
        clean(r.codigo_origen, 30),
        clean(r.codigo_origen, 50),
        clean(r.nombre, 150),
        normalizeUnit(r.unidad_nombre),
        parseFloat(r.precio) || 0,
        parseFloat(r.costo)  || 0,
        true, 'sync', now, now,
      ]);
      const q = buildBatch('productos', pCols, rows, pConf);
      await comp.query(q.text, q.values);
      if ((i / BATCH) % 10 === 9) log(`      ... ${i + BATCH} productos procesados`);
    }
    const pgP = await comp.query('SELECT id, codigo_origen FROM productos WHERE codigo_origen IS NOT NULL');
    const coMap = {};
    pgP.rows.forEach(r => { coMap[r.codigo_origen] = r.id; });
    ingrs.forEach(r => { prodIdMap[r.id_origen] = coMap[clean(r.codigo_origen, 50)]; });
    log(`      OK: ${ingrs.length} productos upsertados.`);
  } else {
    ingrs.forEach((r, i) => { prodIdMap[r.id_origen] = i + 1; });
    log('      (dry-run)');
  }

  // ============================================================
  // FASE 5 — Recetas
  // ============================================================
  log('\n[5/8] Recetas...');
  const recRows = (await sqlPool.request().query(Q_REC)).recordset;
  log(`      ${recRows.length} recetas en menú activo.`);
  const recIdMap = {};

  if (!DRY_RUN) {
    const rCols = ['nombre','codigo_origen','tipo','precio','platos_semana','activa','aud_usuario','created_at','updated_at'];
    const rConf = `ON CONFLICT (codigo_origen) DO UPDATE SET
      nombre=EXCLUDED.nombre, tipo=EXCLUDED.tipo,
      precio=EXCLUDED.precio, updated_at=EXCLUDED.updated_at`;
    for (let i = 0; i < recRows.length; i += BATCH) {
      const chunk = recRows.slice(i, i + BATCH);
      const rows  = chunk.map(r => [
        clean(r.nombre, 150),
        clean(r.codigo_origen, 50),
        clean(r.tipo, 80) || 'General',
        parseFloat(r.precio) || 0,
        0, true, 'sync', now, now,
      ]);
      const q = buildBatch('recetas', rCols, rows, rConf);
      await comp.query(q.text, q.values);
      if ((i / BATCH) % 10 === 9) log(`      ... ${i + BATCH} recetas procesadas`);
    }
    const pgR = await comp.query('SELECT id, codigo_origen FROM recetas WHERE codigo_origen IS NOT NULL');
    const rMap = {};
    pgR.rows.forEach(r => { rMap[r.codigo_origen] = r.id; });
    recRows.forEach(r => { recIdMap[r.id_origen] = rMap[clean(r.codigo_origen, 50)]; });
    log(`      OK: ${recRows.length} recetas upsertadas.`);
  } else {
    recRows.forEach((r, i) => { recIdMap[r.id_origen] = i + 1; });
    log('      (dry-run)');
  }

  // ============================================================
  // FASE 6 — Ingredientes de receta (BOM)
  // ============================================================
  log('\n[6/8] Ingredientes de receta (BOM)...');
  const mxpRows = (await sqlPool.request().query(Q_MXP)).recordset;
  log(`      ${mxpRows.length} líneas encontradas.`);
  let riOk = 0, riSkip = 0;

  if (!DRY_RUN) {
    const riCols = ['receta_id','producto_id','cantidad_por_plato','unidad','aud_usuario','created_at','updated_at'];
    const riConf = `ON CONFLICT (receta_id, producto_id) DO UPDATE SET
      cantidad_por_plato=EXCLUDED.cantidad_por_plato,
      unidad=EXCLUDED.unidad, updated_at=EXCLUDED.updated_at`;
    const validRows = [];
    for (const mxp of mxpRows) {
      const rid = recIdMap[mxp.plato_id_origen];
      const pid = prodIdMap[mxp.ingr_id_origen];
      if (!rid || !pid) { riSkip++; continue; }
      validRows.push([rid, pid, parseFloat(mxp.cantidad) || 0, normalizeUnit(mxp.uni_nombre), 'sync', now, now]);
      riOk++;
    }
    for (let i = 0; i < validRows.length; i += BATCH) {
      const chunk = validRows.slice(i, i + BATCH);
      const q = buildBatch('receta_ingredientes', riCols, chunk, riConf);
      await comp.query(q.text, q.values);
      if ((i / BATCH) % 20 === 19) log(`      ... ${i + BATCH} ingredientes`);
    }
    log(`      OK: ${riOk} insertados, ${riSkip} saltados.`);
  } else {
    mxpRows.forEach(r => {
      recIdMap[r.plato_id_origen] && prodIdMap[r.ingr_id_origen] ? riOk++ : riSkip++;
    });
    log(`      (dry-run) ${riOk} OK, ${riSkip} sin mapping.`);
  }

  // ============================================================
  // FASE 7 — receta_sucursal
  // ============================================================
  log('\n[7/8] Receta ↔ Sucursal...');
  const rsRows = (await sqlPool.request().query(Q_REC_AREA)).recordset;
  log(`      ${rsRows.length} pares receta-área encontrados.`);
  let rsOk = 0, rsSkip = 0;

  if (!DRY_RUN) {
    const rsCols = ['receta_id','sucursal_id','platos_semana','activa','aud_usuario','created_at','updated_at'];
    const rsConf = `ON CONFLICT (receta_id, sucursal_id) DO UPDATE SET
      activa=true, updated_at=EXCLUDED.updated_at`;
    const validRs = [];
    for (const rs of rsRows) {
      const recId  = recIdMap[rs.receta_id_origen];
      const sucId  = AREA_TO_SUC[rs.area_id];
      if (!recId || !sucId) { rsSkip++; continue; }
      validRs.push([recId, sucId, 0, true, 'sync', now, now]);
      rsOk++;
    }
    // Deduplicar: solo uno por (receta_id, sucursal_id) — puede venir duplicado de áreas distintas con mismo sucId
    const seen = new Set();
    const uniqueRs = validRs.filter(r => {
      const key = `${r[0]}-${r[1]}`;
      if (seen.has(key)) return false;
      seen.add(key); return true;
    });
    for (let i = 0; i < uniqueRs.length; i += BATCH) {
      const chunk = uniqueRs.slice(i, i + BATCH);
      const q = buildBatch('receta_sucursal', rsCols, chunk, rsConf);
      await comp.query(q.text, q.values);
      if ((i / BATCH) % 20 === 19) log(`      ... ${i + BATCH} registros`);
    }
    log(`      OK: ${uniqueRs.length} únicos insertados, ${rsSkip} saltados.`);
    rsOk = uniqueRs.length;
  } else {
    rsRows.forEach(r => {
      recIdMap[r.receta_id_origen] && AREA_TO_SUC[r.area_id] ? rsOk++ : rsSkip++;
    });
    log(`      (dry-run) ~${rsOk} únicos, ${rsSkip} sin mapping.`);
  }

  // ============================================================
  // FASE 8 — Modificadores de receta
  // ============================================================
  log('\n[8/8] Modificadores de receta...');
  const modRows = (await sqlPool.request().query(Q_MODS)).recordset;
  log(`      ${modRows.length} filas de modificadores encontradas.`);
  let modOk = 0, modSkip = 0;

  if (!DRY_RUN) {
    // Limpiar modificadores anteriores antes de reinsertar
    await comp.query('DELETE FROM receta_modificadores');

    const mCols = [
      'receta_id','grupo_id_origen','grupo_codigo','grupo_nombre',
      'opcion_nombre','producto_id','cantidad','unidad',
      'aud_usuario','created_at','updated_at',
    ];
    const mConf = `ON CONFLICT (receta_id, grupo_id_origen, opcion_nombre) DO UPDATE SET
      grupo_nombre=EXCLUDED.grupo_nombre,
      producto_id=EXCLUDED.producto_id,
      cantidad=EXCLUDED.cantidad,
      unidad=EXCLUDED.unidad,
      updated_at=EXCLUDED.updated_at`;

    const validMods = [];
    for (const m of modRows) {
      const rid = recIdMap[m.receta_id_origen];
      if (!rid) { modSkip++; continue; }
      // producto_id puede ser null si el modificador no tiene producto asociado
      const pid = m.mod_prod_id_origen ? (prodIdMap[m.mod_prod_id_origen] ?? null) : null;
      validMods.push([
        rid,
        m.grupo_id_origen,
        clean(m.grupo_codigo, 30) || null,
        clean(m.grupo_nombre, 150),
        clean(m.opcion_nombre, 150),
        pid,
        parseFloat(m.cantidad) || 0,
        normalizeUnit(m.uni_nombre),
        'sync', now, now,
      ]);
      modOk++;
    }

    for (let i = 0; i < validMods.length; i += BATCH) {
      const chunk = validMods.slice(i, i + BATCH);
      const q = buildBatch('receta_modificadores', mCols, chunk, mConf);
      await comp.query(q.text, q.values);
      if ((i / BATCH) % 20 === 19) log(`      ... ${i + BATCH} modificadores`);
    }
    log(`      OK: ${modOk} insertados, ${modSkip} saltados.`);
  } else {
    modRows.forEach(m => {
      recIdMap[m.receta_id_origen] ? modOk++ : modSkip++;
    });
    log(`      (dry-run) ${modOk} OK, ${modSkip} sin mapping.`);
  }

  // ── Resumen final ─────────────────────────────────────────────────────────
  log('\n' + '='.repeat(60));
  log('RESUMEN:');
  log(`  Sucursales sync:         ${sucRows.length}`);
  log(`  Categorías:              ${catRows.length}`);
  log(`  Productos (ingredientes):${ingrs.length}`);
  log(`  Recetas (en menú):       ${recRows.length}`);
  log(`  Ingredientes de receta:  ${riOk}`);
  log(`  Receta-sucursal:         ${rsOk}`);
  log(`  Modificadores:           ${modOk}`);
  if (riSkip > 0 || rsSkip > 0 || modSkip > 0)
    log(`  Advertencia saltados:    ${riSkip + rsSkip + modSkip}`);
  log(DRY_RUN
    ? '\nDRY-RUN completado. Corre sin --dry-run para migrar.'
    : '\n✓ Sincronización completada.');
  log('='.repeat(60));

  core.release(); await pgCore.end();
  comp.release(); await pgComp.end();
  await sqlPool.close();
}

main().catch(err => {
  console.error('\n❌ ERROR:', err.message);
  console.error(err.stack);
  process.exit(1);
});
