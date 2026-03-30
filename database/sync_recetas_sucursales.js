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

// ── Mapeo AreasRst → core_db sucursal id ─────────────────────────────────────
// arerstId (olRestaurante.AreasRst) → id en core_db.sucursales
// NOTA: Los sucIds de SQL Server NO coinciden con los IDs de core_db
const AREA_TO_SUC = {
  1:  1,   // ZONA ROSA      → core_db id=1  (RESTAURANTE ZONA ROSA)
  16: 3,   // LA LIBERTAD    → core_db id=3  (RESTAURANTE LA LIBERTAD)
  17: 4,   // AEROPUERTO 1   → core_db id=4  (RESTAURANTE AEROPUERTO 1)
  18: 5,   // AEROPUERTO 2   → core_db id=5  (RESTAURANTE AEROPUERTO 2)
  22: 7,   // PLAZA VENECIA  → core_db id=7  (RESTAURANTE PASEO VENECIA)
  23: 8,   // SANTA ELENA    → core_db id=8  (RESTAURANTE SANTA ELENA)
  25: 9,   // HUIZUCAR       → core_db id=9  (RESTAURANTE HUIZUCAR)
  26: 10,  // OPICO          → core_db id=10 (RESTAURANTE OPICO)
  28: 16,  // MALCRIADAS AE  → core_db id=16 (RES - MALCRIADAS AE2)
  30: 11,  // CASA GUIROLA   → core_db id=11 (RESTAURANTE CASA GUIROLA)
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
// Limpia sufijos internos del POS (ej: "ENSALADA _-" / "AGUA MINERAL _" → nombre limpio)
const cleanModNombre = (s) =>
  clean(s).replace(/[\s_\-]+$/, '').trim();
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
// Prioridad de tipo:
//   1. Menú INFANTIL*/KIDS*/MENU NI*OS → 'Platos Infantiles'
//   2. Categoría SQL Server ya es 'Platos Desayunos' → 'Platos Desayunos'
//   3. Categoría es 'Platos Fuertes' y está en menú DESAYUNO*/BREAKFAST* → 'Platos Desayunos'
//   4. Resto → CPR.cprNombre
const Q_REC = `
  SELECT DISTINCT
    PRO.proId      AS id_origen,
    PRO.proCodigo  AS codigo_origen,
    PRO.proNombre  AS nombre,
    CASE
    WHEN EXISTS (
      SELECT 1 FROM olRestaurante.dbo.BotonesRst B2 WITH(NOLOCK)
      INNER JOIN olRestaurante.dbo.detMenusRst D2 WITH(NOLOCK)
        ON B2.btnrstid = D2.btnrstId AND D2.dmnrstEliminado = 0
      INNER JOIN olRestaurante.dbo.maeMenusRst M2 WITH(NOLOCK)
        ON D2.mmnrstId = M2.mmnrstId AND M2.mmnrstEliminado = 0
      INNER JOIN olRestaurante.dbo.AreasRst A2 WITH(NOLOCK)
        ON M2.arerstId = A2.arerstId AND A2.arerstEliminado = 0
      WHERE B2.proId = PRO.proId AND B2.btnrstEliminado = 0
        AND A2.arerstActiva = 1 AND A2.arerstId IN (${AREA_IN})
        AND (M2.mmnrstNombre LIKE 'INFANTIL%' OR M2.mmnrstNombre LIKE 'KIDS%'
             OR M2.mmnrstNombre LIKE 'MENU NI%OS')
    ) THEN 'Platos Infantiles'
    WHEN CPR.cprNombre = 'Platos Desayunos' THEN 'Platos Desayunos'
    WHEN CPR.cprNombre = 'Platos Fuertes' AND EXISTS (
      SELECT 1 FROM olRestaurante.dbo.BotonesRst B3 WITH(NOLOCK)
      INNER JOIN olRestaurante.dbo.detMenusRst D3 WITH(NOLOCK)
        ON B3.btnrstid = D3.btnrstId AND D3.dmnrstEliminado = 0
      INNER JOIN olRestaurante.dbo.maeMenusRst M3 WITH(NOLOCK)
        ON D3.mmnrstId = M3.mmnrstId AND M3.mmnrstEliminado = 0
      INNER JOIN olRestaurante.dbo.AreasRst A3 WITH(NOLOCK)
        ON M3.arerstId = A3.arerstId AND A3.arerstEliminado = 0
      WHERE B3.proId = PRO.proId AND B3.btnrstEliminado = 0
        AND A3.arerstActiva = 1 AND A3.arerstId IN (${AREA_IN})
        AND (M3.mmnrstNombre LIKE 'DESAYUNO%' OR M3.mmnrstNombre LIKE 'BREAKFAST%')
    ) THEN 'Platos Desayunos'
    ELSE CPR.cprNombre
    END AS tipo,
    ISNULL(PRO.proPrecio, 0) AS precio
  FROM olComun.dbo.MaterialesXProducto MXP WITH(NOLOCK)
  INNER JOIN olComun.dbo.Productos PRO WITH(NOLOCK) ON MXP.proId = PRO.proId AND PRO.proActivo = 1
  LEFT  JOIN olComun.dbo.CategoriasProductos CPR WITH(NOLOCK) ON PRO.cprId = CPR.cprId
  WHERE MXP.mxprEliminado = 0 AND MXP.mxprCantUnidad > 0
    AND PRO.proId IN (${Q_PLATOS_EN_MENU})
  ORDER BY tipo, PRO.proCodigo`;

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
    MXP.proId                              AS receta_id_origen,
    MDR.mdfrstId                           AS grupo_id_origen,
    MDR.mdfrstCodigo                       AS grupo_codigo,
    MDR.mdfrstNombre                       AS grupo_nombre,
    MDD.mdfrstNombre                       AS opcion_nombre,
    PMOD.proId                             AS mod_prod_id_origen,
    PMOD.proCodigo                         AS mod_prod_codigo,
    PMOD.proNombre                         AS mod_prod_nombre,
    ISNULL(PMOD.proCosto, 0)               AS mod_prod_costo,
    CPR_MOD.cprCodigo                      AS mod_cat_codigo,
    ISNULL(MDD.mdfrstCantidadProducto, 0)  AS cantidad,
    UNI.uniNombre                          AS uni_nombre
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
  LEFT  JOIN olComun.dbo.CategoriasProductos CPR_MOD WITH(NOLOCK)
    ON PMOD.cprId = CPR_MOD.cprId
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
  // FASE 0 — Sync sucursales (OMITIDO)
  // Los sucIds de SQL Server no coinciden con los IDs de core_db.
  // Las sucursales se gestionan desde el sistema RRHH (sync_empleados_to_rds.js).
  // ============================================================
  log('\n[0/8] Sucursales → omitido (IDs de SQL Server no coinciden con core_db).');
  const sucRows = [];

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
    // Construir mapa tipo → categoria_id desde receta_categorias
    const rcResult = await comp.query('SELECT id, nombre FROM receta_categorias WHERE activa = true');
    const recatMap = {}; // lower(nombre) → id
    rcResult.rows.forEach(rc => { recatMap[rc.nombre.trim().toLowerCase()] = Number(rc.id); });

    const rCols = ['nombre','codigo_origen','tipo','categoria_id','precio','platos_semana','activa','aud_usuario','created_at','updated_at'];
    const rConf = `ON CONFLICT (codigo_origen) DO UPDATE SET
      nombre=EXCLUDED.nombre, tipo=EXCLUDED.tipo,
      categoria_id=EXCLUDED.categoria_id,
      precio=EXCLUDED.precio, updated_at=EXCLUDED.updated_at`;
    for (let i = 0; i < recRows.length; i += BATCH) {
      const chunk = recRows.slice(i, i + BATCH);
      const rows  = chunk.map(r => {
        const tipo = clean(r.tipo, 80) || 'General';
        const catId = recatMap[tipo.trim().toLowerCase()] ?? null;
        return [
          clean(r.nombre, 150),
          clean(r.codigo_origen, 50),
          tipo,
          catId,
          parseFloat(r.precio) || 0,
          0, true, 'sync', now, now,
        ];
      });
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
    // ── 8a. Migrar productos referenciados por modificadores pero no en prodIdMap ──
    const modProdsExtra = modRows
      .filter(m => m.mod_prod_id_origen && !prodIdMap[m.mod_prod_id_origen] && m.mod_prod_codigo)
      .reduce((acc, m) => { acc[m.mod_prod_id_origen] = m; return acc; }, {});
    const extraList = Object.values(modProdsExtra);
    if (extraList.length > 0) {
      log(`      Migrando ${extraList.length} productos referenciados en modificadores...`);
      const pCols2 = ['categoria_id','codigo','codigo_origen','nombre','unidad','precio','costo','activo','aud_usuario','created_at','updated_at'];
      const pConf2 = `ON CONFLICT (codigo) DO UPDATE SET
        nombre=EXCLUDED.nombre, costo=EXCLUDED.costo,
        unidad=EXCLUDED.unidad, updated_at=EXCLUDED.updated_at`;
      for (let i = 0; i < extraList.length; i += BATCH) {
        const chunk = extraList.slice(i, i + BATCH);
        const rows  = chunk.map(m => [
          catIdMap[m.mod_cat_codigo] ?? catIdMap['__fb__'],
          clean(m.mod_prod_codigo, 30),
          clean(m.mod_prod_codigo, 50),
          clean(m.mod_prod_nombre, 150),
          normalizeUnit(m.uni_nombre),
          0,
          parseFloat(m.mod_prod_costo) || 0,
          true, 'sync', now, now,
        ]);
        const q = buildBatch('productos', pCols2, rows, pConf2);
        await comp.query(q.text, q.values);
      }
      // Actualizar prodIdMap con los nuevos registros
      const newP = await comp.query(
        `SELECT id, codigo_origen FROM productos WHERE codigo_origen = ANY($1::text[])`,
        [extraList.map(m => clean(m.mod_prod_codigo, 50))]
      );
      newP.rows.forEach(r => {
        const orig = extraList.find(m => clean(m.mod_prod_codigo, 50) === r.codigo_origen);
        if (orig) prodIdMap[orig.mod_prod_id_origen] = r.id;
      });
      log(`      OK: ${extraList.length} productos de modificadores upsertados.`);
    }

    // ── 8b. Limpiar modificadores anteriores antes de reinsertar ──────────────
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
      // producto_id puede ser null si el modificador no tiene producto asociado en SQL Server
      const pid = m.mod_prod_id_origen ? (prodIdMap[m.mod_prod_id_origen] ?? null) : null;
      validMods.push([
        rid,
        m.grupo_id_origen,
        clean(m.grupo_codigo, 30) || null,
        cleanModNombre(m.grupo_nombre).slice(0, 150),
        cleanModNombre(m.opcion_nombre).slice(0, 150),
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

  // ============================================================
  // FASE 8.5 — Recetas nuevas sin botón POS (activas con BOM, no en RDS)
  // Estrategia: cualquier producto activo en SS con ingredientes cuya categoría
  // exista en receta_categorias, y que no tenga aún codigo_origen en recetas.
  // Se importan sin asignación de sucursal — el usuario las asigna en el sistema.
  // ============================================================
  log('\n[8.5] Recetas nuevas sin botón POS...');
  let nuevasOk = 0, nuevasSkip = 0;

  // Cargar categorías válidas de receta desde PG (nombre → categoria_id)
  const recatResult = await comp.query('SELECT id, nombre FROM receta_categorias WHERE activa = true');
  const recatNombreMap = {}; // lower(nombre) → { id, nombre }
  recatResult.rows.forEach(rc => { recatNombreMap[rc.nombre.trim().toLowerCase()] = { id: Number(rc.id), nombre: rc.nombre }; });
  const validCatNames = Object.keys(recatNombreMap);

  // Códigos ya existentes en recetas
  const existentesResult = await comp.query('SELECT codigo_origen FROM recetas WHERE codigo_origen IS NOT NULL');
  const existentesCodigos = new Set(existentesResult.rows.map(r => r.codigo_origen));

  if (!DRY_RUN) {
    // Consultar SS: todos los productos activos con BOM en categorías de receta
    const allRecetasSSReq = sqlPool.request();
    const catNamesForSQL = validCatNames.map((n, i) => { allRecetasSSReq.input(`vcat${i}`, sql.VarChar, recatResult.rows[i]?.nombre ?? n); return `@vcat${i}`; });
    // Rebuild properly: use recatResult.rows for correct names
    const allRecetasSSReq2 = sqlPool.request();
    recatResult.rows.forEach((rc, i) => allRecetasSSReq2.input(`rc${i}`, sql.VarChar, rc.nombre));
    const rcPh = recatResult.rows.map((_, i) => `@rc${i}`).join(',');

    const allRecetasSS = (await allRecetasSSReq2.query(`
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
        AND cpr.cprNombre NOT LIKE '%Sub-Receta%'
        AND cpr.cprNombre IN (${rcPh})
    `)).recordset;

    // Filtrar solo los que NO están en RDS aún
    const nuevas = allRecetasSS.filter(r => !existentesCodigos.has(clean(r.codigo, 50)));
    log(`      ${allRecetasSS.length} recetas activas con BOM en SS. ${nuevas.length} no están en RDS.`);

    if (nuevas.length > 0) {
      // Insertar recetas nuevas
      const rCols2 = ['nombre','codigo_origen','tipo','categoria_id','precio','platos_semana','activa','aud_usuario','created_at','updated_at'];
      const rConf2 = `ON CONFLICT (codigo_origen) DO NOTHING`;
      const rRet2  = 'RETURNING id, codigo_origen';
      const nuevaRecetaMap = {}; // codigo → pg id

      for (let i = 0; i < nuevas.length; i += BATCH) {
        const chunk = nuevas.slice(i, i + BATCH);
        const rows  = chunk.map(r => {
          const tipo  = clean(r.tipo, 80) || 'General';
          const catId = recatNombreMap[tipo.trim().toLowerCase()]?.id ?? null;
          return [
            clean(r.nombre, 150), clean(r.codigo, 50), tipo, catId,
            parseFloat(r.precio) || 0, 0, true, 'sync_nuevas', now, now,
          ];
        });
        const q = buildBatch('recetas', rCols2, rows, rConf2, rRet2);
        const res = await comp.query(q.text, q.values);
        res.rows.forEach(row => { nuevaRecetaMap[row.codigo_origen] = row.id; });
        nuevasOk += res.rows.length;
      }
      log(`      OK: ${nuevasOk} recetas nuevas insertadas.`);

      // Migrar ingredientes de recetas nuevas desde SS
      const nuevosIds = nuevas.map(r => r.id_origen);
      let ingrNuevasOk = 0, ingrNuevasSkip = 0;

      for (let ci = 0; ci < nuevosIds.length; ci += 500) {
        const idChunk = nuevosIds.slice(ci, ci + 500);
        const ingrReq = sqlPool.request();
        idChunk.forEach((id, i) => ingrReq.input(`ni${ci + i}`, sql.Int, id));
        const niPh = idChunk.map((_, i) => `@ni${ci + i}`).join(',');

        const ingrRows2 = (await ingrReq.query(`
          SELECT
            mx.proId         AS plato_id_origen,
            mx.proIdMaterial AS ingr_id_origen,
            ingr.proCodigo   AS ingr_codigo,
            SUM(mx.mxprCantUnidad) AS cantidad,
            MAX(ISNULL(uni.uniNombre,'u')) AS uni_nombre
          FROM olComun.dbo.MaterialesXProducto mx WITH(NOLOCK)
          INNER JOIN olComun.dbo.Productos ingr WITH(NOLOCK) ON ingr.proId = mx.proIdMaterial
          LEFT  JOIN olComun.dbo.Unidades uni WITH(NOLOCK) ON uni.uniId = mx.uniId
          WHERE mx.mxprEliminado = 0 AND mx.mxprCantUnidad > 0
            AND mx.proId IN (${niPh})
          GROUP BY mx.proId, mx.proIdMaterial, ingr.proCodigo
        `)).recordset;

        for (const ingr of ingrRows2) {
          const recPgId = nuevaRecetaMap[clean(nuevas.find(n => n.id_origen === ingr.plato_id_origen)?.codigo ?? '', 50)];
          const prodPgId = prodIdMap[ingr.ingr_id_origen];
          if (!recPgId || !prodPgId) { ingrNuevasSkip++; continue; }
          await comp.query(`
            INSERT INTO receta_ingredientes (receta_id, producto_id, cantidad_por_plato, unidad, aud_usuario, created_at, updated_at)
            VALUES ($1,$2,$3,$4,'sync_nuevas',$5,$5)
            ON CONFLICT (receta_id, producto_id) DO NOTHING
          `, [recPgId, prodPgId, parseFloat(ingr.cantidad) || 0, normalizeUnit(ingr.uni_nombre), now]);
          ingrNuevasOk++;
        }
      }
      log(`      Ingredientes nuevas recetas: ${ingrNuevasOk} insertados, ${ingrNuevasSkip} saltados.`);
    } else {
      log('      Sin recetas nuevas que importar.');
    }
  } else {
    const allRecetasSSReqDR = sqlPool.request();
    recatResult.rows.forEach((rc, i) => allRecetasSSReqDR.input(`dr${i}`, sql.VarChar, rc.nombre));
    const drPh = recatResult.rows.map((_, i) => `@dr${i}`).join(',');
    const allSSdr = (await allRecetasSSReqDR.query(`
      SELECT COUNT(DISTINCT p.proId) AS cnt
      FROM olComun.dbo.Productos p WITH(NOLOCK)
      INNER JOIN olComun.dbo.CategoriasProductos cpr WITH(NOLOCK) ON p.cprId = cpr.cprId
      INNER JOIN olComun.dbo.MaterialesXProducto mx WITH(NOLOCK) ON mx.proId = p.proId AND mx.mxprEliminado=0 AND mx.mxprCantUnidad>0
      WHERE p.proActivo=1 AND cpr.cprNombre NOT LIKE '%Sub-Receta%' AND cpr.cprNombre IN (${drPh})
    `)).recordset;
    const totalSS = Number(allSSdr[0].cnt);
    nuevasOk = totalSS - existentesCodigos.size;
    log(`      (dry-run) ~${nuevasOk} recetas nuevas se importarían.`);
  }

  // ── Resumen final ─────────────────────────────────────────────────────────
  log('\n' + '='.repeat(60));
  log('RESUMEN:');
  log(`  Sucursales sync:         ${sucRows.length}`);
  log(`  Categorías:              ${catRows.length}`);
  log(`  Productos (ingredientes):${ingrs.length}`);
  log(`  Recetas (en menú):       ${recRows.length}`);
  log(`  Recetas nuevas (sin POS):${nuevasOk}`);
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
