/**
 * sync_sub_recetas.js
 *
 * Migra las sub-recetas desde SQL Server (olcomun) hacia PostgreSQL (compras_db).
 *
 * ¿Qué es una sub-receta?
 *   Un producto que aparece como ingrediente en OTRAS recetas (proIdMaterial en MXP)
 *   Y TAMBIÉN tiene sus propios ingredientes (proId en MXP).
 *   En SQL Server su proCosto = 0 porque el sistema lo calcula dinámicamente.
 *
 * Pasos:
 *   1. Detectar sub-recetas en SQL Server
 *   2. Crear/actualizar entradas en recetas (tipo_receta = 'sub_receta')
 *   3. Crear receta_ingredientes de cada sub-receta (sus propias materias primas)
 *   4. Actualizar receta_ingredientes existentes: producto_id → sub_receta_id
 *
 * Uso: node sync_sub_recetas.js [--dry-run]
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const sqlConfig = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

const pgConfig = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'compras_db', user: 'cadejo_admin',
  password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
  keepAlive: true,
  connectionTimeoutMillis: 30000,
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

// ── Queries SQL Server ────────────────────────────────────────────────────────

/**
 * Sub-recetas: SOLO productos de la categoría "Platos Sub-Recetas" (cprId=392)
 * que además tienen sus propios ingredientes en MXP.
 * Restricción por categoría evita que platos fuertes, cervezas, etc. que
 * aparecen como ingredientes en combos sean detectados incorrectamente.
 */
const Q_SUB_RECETAS = `
SELECT DISTINCT
  p.proId        AS id_origen,
  p.proCodigo    AS codigo,
  p.proNombre    AS nombre,
  ISNULL(cpr.cprNombre, 'Sub-Receta') AS tipo
FROM olComun.dbo.Productos p WITH (NOLOCK)
-- Solo categoría "Platos Sub-Recetas"
INNER JOIN olComun.dbo.CategoriasProductos cpr WITH (NOLOCK)
  ON p.cprId = cpr.cprId
  AND cpr.cprNombre = 'Platos Sub-Recetas'
-- Debe tener sus propios ingredientes
INNER JOIN olComun.dbo.MaterialesXProducto mx_como_padre WITH (NOLOCK)
  ON mx_como_padre.proId = p.proId
  AND mx_como_padre.mxprEliminado = 0
ORDER BY p.proCodigo`;

/**
 * Ingredientes de cada sub-receta (las materias primas que la componen).
 */
const Q_SUB_INGREDIENTES = `
SELECT
  mx.proId           AS sub_id_origen,
  mx.proIdMaterial   AS ingr_id_origen,
  ingr.proCodigo     AS ingr_codigo,
  mx.mxprCantUnidad  AS cantidad,
  ISNULL(uni.uniNombre, 'u') AS unidad
FROM olComun.dbo.MaterialesXProducto mx WITH (NOLOCK)
INNER JOIN olComun.dbo.Productos ingr WITH (NOLOCK)
  ON ingr.proId = mx.proIdMaterial
LEFT JOIN olComun.dbo.Unidades uni WITH (NOLOCK)
  ON uni.uniId = mx.uniId
WHERE mx.mxprEliminado = 0
  AND mx.mxprCantUnidad > 0
  AND mx.proId IN (
    SELECT DISTINCT p2.proId
    FROM olComun.dbo.Productos p2 WITH (NOLOCK)
    INNER JOIN olComun.dbo.CategoriasProductos cpr2 WITH (NOLOCK)
      ON p2.cprId = cpr2.cprId AND cpr2.cprNombre = 'Platos Sub-Recetas'
    INNER JOIN olComun.dbo.MaterialesXProducto par WITH (NOLOCK)
      ON par.proId = p2.proId AND par.mxprEliminado = 0
  )`;

// ─────────────────────────────────────────────────────────────────────────────
async function main() {
  log('================================================');
  log('SYNC SUB-RECETAS: SQL Server → PostgreSQL');
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

  // ── 1. Cargar sub-recetas desde SQL Server ─────────────────────────────────
  log('');
  log('[1/4] Detectando sub-recetas en SQL Server...');
  const subRows = (await sqlPool.request().query(Q_SUB_RECETAS)).recordset;
  log(`      ${subRows.length} sub-recetas encontradas.`);

  if (subRows.length === 0) {
    log('      Sin sub-recetas. Abortando.');
    pg.release(); await pool.end(); await sqlPool.close(); return;
  }

  // ── 2. Cargar ingredientes de sub-recetas desde SQL Server ─────────────────
  log('');
  log('[2/4] Cargando ingredientes de sub-recetas...');
  const subIngrRows = (await sqlPool.request().query(Q_SUB_INGREDIENTES)).recordset;
  log(`      ${subIngrRows.length} líneas de ingredientes.`);

  // ── 1b. Detectar modifier sub-recetas (no aparecen como proIdMaterial en MXP) ──
  log('');
  log('[1b] Detectando modifier sub-recetas (costo=0 en productos, con ingredientes en SQL Server)...');

  // Productos referenciados en receta_modificadores con costo=0 en PG
  const modSubProds = (await pg.query(`
    SELECT DISTINCT p.codigo_origen, p.codigo, p.nombre
    FROM productos p
    INNER JOIN receta_modificadores rm ON rm.producto_id = p.id
    WHERE p.costo = 0
      AND p.codigo_origen IS NOT NULL
      AND p.codigo_origen != ''
  `)).rows;

  // Excluir los ya detectados por Q_SUB_RECETAS
  const subCodigosSet = new Set(subRows.map(r => String(r.codigo ?? '').trim()));
  const extraProds = modSubProds.filter(p => !subCodigosSet.has(String(p.codigo ?? '').trim()));
  log(`      ${modSubProds.length} modifier products con costo=0. ${extraProds.length} no detectados aún como sub-recetas.`);

  if (extraProds.length > 0) {
    // Buscar en SQL Server cuáles tienen ingredientes propios en MXP
    const extraCodigos = extraProds.map(p => p.codigo_origen).filter(Boolean);

    // Construir query con parámetros nombrados para mssql
    const extraReq = sqlPool.request();
    extraCodigos.forEach((c, i) => extraReq.input(`ec${i}`, sql.VarChar, c));
    const ecPh = extraCodigos.map((_, i) => `@ec${i}`).join(',');

    const extraSubData = (await extraReq.query(`
      SELECT DISTINCT
        p.proId        AS id_origen,
        p.proCodigo    AS codigo,
        p.proNombre    AS nombre,
        ISNULL(cpr.cprNombre, 'Sub-Receta') AS tipo
      FROM olComun.dbo.Productos p WITH (NOLOCK)
      INNER JOIN olComun.dbo.MaterialesXProducto mx WITH (NOLOCK)
        ON mx.proId = p.proId AND mx.mxprEliminado = 0 AND mx.mxprCantUnidad > 0
      LEFT JOIN olComun.dbo.CategoriasProductos cpr WITH (NOLOCK) ON p.cprId = cpr.cprId
      WHERE p.proCodigo IN (${ecPh})
    `)).recordset;

    log(`      ${extraSubData.length} modifier sub-recetas con ingredientes en SQL Server.`);

    if (extraSubData.length > 0) {
      const extraSubIds = extraSubData.map(r => r.id_origen);
      const ingrReq = sqlPool.request();
      extraSubIds.forEach((id, i) => ingrReq.input(`si${i}`, sql.Int, id));
      const siPh = extraSubIds.map((_, i) => `@si${i}`).join(',');

      const extraIngrData = (await ingrReq.query(`
        SELECT
          mx.proId           AS sub_id_origen,
          mx.proIdMaterial   AS ingr_id_origen,
          ingr.proCodigo     AS ingr_codigo,
          mx.mxprCantUnidad  AS cantidad,
          ISNULL(uni.uniNombre, 'u') AS unidad
        FROM olComun.dbo.MaterialesXProducto mx WITH (NOLOCK)
        INNER JOIN olComun.dbo.Productos ingr WITH (NOLOCK)
          ON ingr.proId = mx.proIdMaterial
        LEFT JOIN olComun.dbo.Unidades uni WITH (NOLOCK)
          ON uni.uniId = mx.uniId
        WHERE mx.mxprEliminado = 0
          AND mx.mxprCantUnidad > 0
          AND mx.proId IN (${siPh})
      `)).recordset;

      log(`      ${extraIngrData.length} ingredientes de modifier sub-recetas. Añadiendo a subRows/subIngrRows...`);
      subRows.push(...extraSubData);
      subIngrRows.push(...extraIngrData);
    }
  } else {
    log('      Ninguna modifier sub-receta adicional detectada.');
  }

  // ── 3. Migrar sub-recetas a PostgreSQL ────────────────────────────────────
  log('');
  log('[3/4] Upsertando sub-recetas en recetas...');

  // Mapa: codigo_origen → pg receta id (para sub-recetas ya existentes)
  const subRecetaPgIdMap = {}; // codigo → pg id

  if (!DRY_RUN) {
    // Cargar productos pg para mapear codigo → producto_id (para ingredientes)
    const prodRows = await pg.query('SELECT id, codigo FROM productos WHERE activo = true');
    const prodCodigoMap = {}; // codigo → pg id
    prodRows.rows.forEach(r => { prodCodigoMap[r.codigo] = r.id; });

    // ── Leer sub-recetas modificadas localmente por el usuario ──────────────
    // Estas NO se sobreescriben: se omiten en el upsert y en el delete/re-insert
    // de ingredientes para preservar los cambios que el usuario hizo en el sistema.
    const modLocRes = await pg.query(`
      SELECT codigo_origen
      FROM recetas
      WHERE tipo_receta = 'sub_receta'
        AND modificado_localmente = true
        AND codigo_origen IS NOT NULL
    `);
    const modificadasLocalmente = new Set(modLocRes.rows.map(r => String(r.codigo_origen).trim()));
    if (modificadasLocalmente.size > 0) {
      log(`      ${modificadasLocalmente.size} sub-receta(s) con modificado_localmente=true — se omitirán del sync.`);
    }

    // Filtrar subRows: excluir las modificadas localmente
    const subRowsFiltradas = subRows.filter(r => !modificadasLocalmente.has(String(r.codigo ?? '').trim()));
    const omitidas = subRows.length - subRowsFiltradas.length;
    if (omitidas > 0) log(`      ${omitidas} sub-receta(s) omitidas por modificado_localmente.`);
    // Reemplazar subRows por la versión filtrada para todo el resto del proceso
    subRows.length = 0;
    subRows.push(...subRowsFiltradas);

    // ── Detectar ingredientes de sub-recetas que no están en productos → insertarlos ──
    const allIngrCodigos = [...new Set(subIngrRows.map(r => String(r.ingr_codigo ?? '').trim()).filter(Boolean))];
    const missingCodigos = allIngrCodigos.filter(c => !prodCodigoMap[c]);

    if (missingCodigos.length > 0) {
      log(`      Detectados ${missingCodigos.length} ingredientes no presentes en productos. Sincronizando desde SQL Server...`);
      const chunkSize = 100;
      for (let ci = 0; ci < missingCodigos.length; ci += chunkSize) {
        const chunk = missingCodigos.slice(ci, ci + chunkSize);
        const req = sqlPool.request();
        chunk.forEach((c, i) => req.input(`mc${i}`, sql.VarChar, c));
        const ph = chunk.map((_, i) => `@mc${i}`).join(',');
        const missingData = (await req.query(`
          SELECT p.proId, p.proCodigo AS codigo, p.proNombre AS nombre,
                 ISNULL(p.proCosto, 0) AS costo, ISNULL(p.proPrecio, 0) AS precio,
                 cpr.cprCodigo AS cat_codigo, uni.uniNombre AS unidad_nombre
          FROM olComun.dbo.Productos p WITH(NOLOCK)
          LEFT JOIN olComun.dbo.CategoriasProductos cpr WITH(NOLOCK) ON p.cprId = cpr.cprId
          LEFT JOIN olComun.dbo.Unidades uni WITH(NOLOCK) ON uni.uniId = p.uniId
          WHERE p.proCodigo IN (${ph})
        `)).recordset;

        if (missingData.length === 0) continue;

        // Obtener catIdMap desde categorias existentes en PG (key → id)
        const catPgRes = await pg.query('SELECT id, key FROM categorias');
        const catKeyMap = {};
        catPgRes.rows.forEach(r => { catKeyMap[String(r.key).trim()] = Number(r.id); });
        const fallbackCatId = catPgRes.rows.find(r => r.key === 'SIN-CAT')?.id
          ?? catPgRes.rows[0]?.id;

        const pCols = ['categoria_id','codigo','codigo_origen','nombre','unidad','precio','costo','origen','activo','aud_usuario','created_at','updated_at'];
        const pConf = `ON CONFLICT (codigo) DO UPDATE SET nombre=EXCLUDED.nombre, costo=EXCLUDED.costo, precio=EXCLUDED.precio, updated_at=EXCLUDED.updated_at`;
        const pRet  = 'RETURNING id, codigo';
        const pRows = missingData.map(r => {
          const cod    = clean(r.codigo, 30);
          const origen = cod.toUpperCase().startsWith('CP') ? 'centro_produccion' : 'restaurante';
          const catKey = r.cat_codigo ? clean(r.cat_codigo, 30).replace(/[^a-zA-Z0-9\-_]/g, '-') : null;
          const catId  = (catKey && catKeyMap[catKey]) ? catKeyMap[catKey] : fallbackCatId;
          return [
            catId, cod, clean(r.codigo, 50), clean(r.nombre, 150),
            normalizeUnit(r.unidad_nombre),
            parseFloat(r.precio) || 0, parseFloat(r.costo) || 0,
            origen, true, 'sync_sub_recetas', now, now,
          ];
        });
        const qP = buildBatch('productos', pCols, pRows, pConf, pRet);
        const insRes = await pg.query(qP.text, qP.values);
        insRes.rows.forEach(r => { prodCodigoMap[r.codigo] = r.id; });
        log(`      Insertados ${insRes.rows.length} productos faltantes.`);
      }
    }

    // Upsert sub-recetas en recetas
    const rCols = ['nombre','codigo_origen','tipo','tipo_receta','platos_semana','activa','precio','aud_usuario','created_at','updated_at'];
    // Q_SUB_RECETAS ya filtra por categoría 'Platos Sub-Recetas', así que todos los
    // registros procesados aquí son genuinamente sub-recetas — se puede actualizar
    // tipo_receta sin riesgo de sobreescribir platos del menú.
    // WHERE recetas.modificado_localmente = false → doble protección por si acaso
    // (el filtro de subRowsFiltradas ya debió excluirlos, pero esto es seguro).
    const rConf = `ON CONFLICT (codigo_origen) DO UPDATE SET
      nombre=EXCLUDED.nombre, tipo=EXCLUDED.tipo, tipo_receta=EXCLUDED.tipo_receta,
      updated_at=EXCLUDED.updated_at
      WHERE recetas.modificado_localmente = false`;
    const rRet = 'RETURNING id, codigo_origen';

    let insertedCount = 0;
    for (let i = 0; i < subRows.length; i += BATCH) {
      const chunk = subRows.slice(i, i + BATCH);
      const rows  = chunk.map(r => [
        clean(r.nombre, 150),
        clean(r.codigo, 50),
        clean(r.tipo, 80) || 'Sub-Receta',
        'sub_receta',
        0, true, 0,
        'sync_sub_recetas', now, now,
      ]);
      const q = buildBatch('recetas', rCols, rows, rConf, rRet);
      const res = await pg.query(q.text, q.values);
      res.rows.forEach(r => { subRecetaPgIdMap[r.codigo_origen] = r.id; });
      insertedCount += chunk.length;
    }

    // Recargar por si acaso (el RETURNING puede tener race conditions en lotes)
    const existingRec = await pg.query(
      "SELECT id, codigo_origen FROM recetas WHERE tipo_receta = 'sub_receta'"
    );
    existingRec.rows.forEach(r => { subRecetaPgIdMap[r.codigo_origen] = r.id; });

    log(`      OK: ${insertedCount} sub-recetas upsertadas. Mapa: ${Object.keys(subRecetaPgIdMap).length} entradas.`);

    // ── 4a. Crear receta_ingredientes de cada sub-receta ────────────────────
    log('');
    log('[4/4] Migrando ingredientes de sub-recetas...');

    // Agrupar ingredientes por sub_id_origen
    const ingrPorSub = {};
    subIngrRows.forEach(r => {
      if (!ingrPorSub[r.sub_id_origen]) ingrPorSub[r.sub_id_origen] = [];
      ingrPorSub[r.sub_id_origen].push(r);
    });

    // Mapa SQL Server proId → codigo (para sub-recetas)
    const subCodigoMap = {}; // proId → codigo
    subRows.forEach(r => { subCodigoMap[r.id_origen] = r.codigo; });

    let riOk = 0, riSkip = 0;
    const riCols = ['receta_id','producto_id','cantidad_por_plato','unidad','aud_usuario','created_at','updated_at'];
    const riConf = ''; // INSERT simple (borramos primero)

    const subRecetaIds = Object.values(subRecetaPgIdMap).filter(Boolean);

    // Borrar ingredientes anteriores de las sub-recetas migradas
    if (subRecetaIds.length > 0) {
      for (let i = 0; i < subRecetaIds.length; i += 500) {
        const chunk = subRecetaIds.slice(i, i + 500);
        await pg.query('DELETE FROM receta_ingredientes WHERE receta_id = ANY($1::int[])', [chunk]);
      }
    }

    for (const [subIdOrigen, ingredientes] of Object.entries(ingrPorSub)) {
      const subCodigo = subCodigoMap[subIdOrigen];
      const subPgId   = subRecetaPgIdMap[subCodigo];
      if (!subPgId) { log(`      WARN: sin pg id para sub ${subIdOrigen}`); riSkip += ingredientes.length; continue; }

      const validRows = [];
      for (const ing of ingredientes) {
        const prodPgId = prodCodigoMap[ing.ingr_codigo];
        if (!prodPgId) { riSkip++; continue; }
        validRows.push([
          subPgId,
          prodPgId,
          parseFloat(ing.cantidad) || 0,
          normalizeUnit(ing.unidad),
          'sync_sub_recetas', now, now,
        ]);
        riOk++;
      }

      if (validRows.length > 0) {
        for (let i = 0; i < validRows.length; i += BATCH) {
          const chunk = validRows.slice(i, i + BATCH);
          const q = buildBatch('receta_ingredientes', riCols, chunk, riConf);
          await pg.query(q.text, q.values);
        }
      }
    }

    log(`      Ingredientes insertados: ${riOk}, saltados: ${riSkip}`);

    // ── 4b. Actualizar receta_ingredientes existentes: producto_id → sub_receta_id ──
    log('');
    log('[4b] Actualizando receta_ingredientes: producto_id → sub_receta_id...');

    const updateResult = await pg.query(`
      UPDATE receta_ingredientes ri
      SET
        sub_receta_id = r.id,
        producto_id   = NULL
      FROM recetas r
      INNER JOIN productos p ON p.codigo = r.codigo_origen
      WHERE ri.producto_id = p.id
        AND r.tipo_receta = 'sub_receta'
        AND ri.sub_receta_id IS NULL
    `);

    log(`      ${updateResult.rowCount} filas actualizadas a sub_receta_id.`);

    // ── 4c. Calcular y almacenar costo de modifier sub-recetas (unidad='u') ──
    // La tabla ConversionesXUnidades de SQL Server es incompleta (muchas entradas "inactiva").
    // Calculamos en JS usando la misma tabla de conversión que RecetasController::convertirCosto.
    log('');
    log('[4c] Calculando costos de modifier sub-recetas (unidad=u)...');

    // Misma tabla de conversión que en PHP: factor = cuántas unidades FROM caben en 1 TO
    const CONV_JS = {
      'lb':    { 'oz': 1/16,       'g': 1/453.592,  'kg': 1/0.453592 },
      'kg':    { 'g':  1/1000,     'oz': 1/35.274,  'lb': 1/2.20462  },
      'oz':    { 'lb': 16,         'g': 28.3495,    'kg': 28.3495/1000 },
      'g':     { 'lb': 453.592,    'kg': 1000,      'oz': 1/28.3495  },
      'lt':    { 'ml': 1/1000,     'oz fl': 1/33.814, 'galon': 3.78541 },
      'galon': { 'oz fl': 1/128,   'lt': 1/3.78541, 'ml': 1/3785.41  },
      'oz fl': { 'galon': 128,     'lt': 33.814,    'ml': 33.814/1000 },
      'ml':    { 'lt': 1000,       'oz fl': 1000/33.814, 'galon': 3785.41 },
    };
    const convertirCostoJs = (costo, desde, hacia) => {
      if (!desde || !hacia || desde === hacia) return costo;
      const factor = CONV_JS[desde]?.[hacia];
      return factor != null ? costo * factor : costo; // sin factor conocido: asumir misma unidad
    };

    // Buscar productos con costo=0, unidad='u', que tienen receta sub_receta con ingredientes
    const modSubCostoCero = (await pg.query(`
      SELECT p.id AS prod_id, p.codigo AS prod_codigo, r.id AS receta_id
      FROM productos p
      INNER JOIN recetas r ON r.codigo_origen = p.codigo AND r.tipo_receta = 'sub_receta'
      WHERE p.costo = 0
        AND p.unidad IN ('u', 'unidad', 'porcion', 'rebanada')
    `)).rows;

    if (modSubCostoCero.length === 0) {
      log('      Ningún modifier sub-receta con costo=0 encontrado. Saltando.');
    } else {
      log(`      ${modSubCostoCero.length} modifier sub-recetas con costo=0 a calcular.`);

      // Cargar todos sus ingredientes con costo y unidades desde PG
      const recetaIds = modSubCostoCero.map(r => r.receta_id);
      const ingrData = (await pg.query(`
        SELECT
          ri.receta_id,
          ri.cantidad_por_plato AS cantidad,
          ri.unidad             AS ingr_unidad,
          p.costo               AS prod_costo,
          p.unidad              AS prod_unidad
        FROM receta_ingredientes ri
        INNER JOIN productos p ON p.id = ri.producto_id
        WHERE ri.receta_id = ANY($1::int[])
          AND p.costo > 0
      `, [recetaIds])).rows;

      // Agrupar por receta_id
      const ingrPorReceta = {};
      ingrData.forEach(r => {
        if (!ingrPorReceta[r.receta_id]) ingrPorReceta[r.receta_id] = [];
        ingrPorReceta[r.receta_id].push(r);
      });

      let costoUpdated = 0;
      for (const sub of modSubCostoCero) {
        const ingredientes = ingrPorReceta[sub.receta_id] ?? [];
        if (ingredientes.length === 0) continue;

        const costo = ingredientes.reduce((sum, ing) => {
          const costoUnitario = convertirCostoJs(
            parseFloat(ing.prod_costo) || 0,
            (ing.prod_unidad ?? '').trim().toLowerCase(),
            (ing.ingr_unidad ?? '').trim().toLowerCase()
          );
          return sum + (parseFloat(ing.cantidad) || 0) * costoUnitario;
        }, 0);

        if (costo > 0) {
          await pg.query(
            'UPDATE productos SET costo = $1, updated_at = NOW() WHERE id = $2 AND costo = 0',
            [costo, sub.prod_id]
          );
          log(`        ${sub.prod_codigo}: $${costo.toFixed(4)}`);
          costoUpdated++;
        }
      }
      log(`      ${costoUpdated} productos actualizados con costo de modifier sub-receta.`);
    }

    if (false) { // Disabled old 4c (replaced by new step 4c above)
    // ── 4c. Calcular costos de sub-recetas directamente en SQL Server ──────
    // Para sub-recetas cuyo proCosto = 0, calculamos el costo como
    // SUM(material.proCosto × mxprCantUnidad) usando los mismos datos que el backoffice.
    log('');
    log('[4c] Calculando costos de sub-recetas desde SQL Server...');

    // Codigos de sub-recetas cuyo costo en pg = 0
    const codigosConCostoCero = (await pg.query(`
      SELECT p.codigo FROM productos p
      WHERE p.costo = 0
        AND EXISTS (SELECT 1 FROM recetas r WHERE r.codigo_origen = p.codigo AND r.tipo_receta = 'sub_receta')
    `)).rows.map(r => r.codigo);

    if (codigosConCostoCero.length === 0) {
      log('      Ninguna sub-receta con costo=0 encontrada en PG. Saltando.');
    } else {
      log(`      ${codigosConCostoCero.length} sub-recetas con costo=0 a calcular.`);

      // Traer los costos calculados desde SQL Server
      // El mismo cálculo que usa el backoffice: SUM(material.proCosto × mxprCantUnidad)
      const codigosPlaceholders = codigosConCostoCero.map((_, i) => `@c${i}`).join(',');
      const costoRequest = sqlPool.request();
      codigosConCostoCero.forEach((c, i) => costoRequest.input(`c${i}`, c));

      const costoQuery = `
        SELECT
          parent.proCodigo AS codigo,
          SUM(material.proCosto * mxp.mxprCantUnidad) AS costo_calculado
        FROM olComun.dbo.MaterialesXProducto mxp WITH (NOLOCK)
        INNER JOIN olComun.dbo.Productos parent WITH (NOLOCK)
          ON parent.proId = mxp.proId
        INNER JOIN olComun.dbo.Productos material WITH (NOLOCK)
          ON material.proId = mxp.proIdMaterial
        WHERE mxp.mxprEliminado = 0
          AND material.proCosto > 0
          AND parent.proCodigo IN (${codigosPlaceholders})
        GROUP BY parent.proCodigo
        HAVING SUM(material.proCosto * mxp.mxprCantUnidad) > 0
      `;

      const costoRows = (await costoRequest.query(costoQuery)).recordset;
      log(`      ${costoRows.length} sub-recetas con costo calculado en SQL Server.`);

      let costoUpdated = 0;
      for (let i = 0; i < costoRows.length; i += BATCH) {
        const chunk = costoRows.slice(i, i + BATCH);
        for (const row of chunk) {
          await pg.query(
            'UPDATE productos SET costo = $1, updated_at = NOW() WHERE codigo = $2 AND costo = 0',
            [parseFloat(row.costo_calculado) || 0, row.codigo]
          );
          costoUpdated++;
        }
      }
      log(`      ${costoUpdated} productos actualizados con costo calculado.`);
    }
    } // end if(false) - step 4c disabled

  } else {
    // DRY RUN
    log(`      (dry-run) ${subRows.length} sub-recetas se crearían.`);
    log(`      (dry-run) ${subIngrRows.length} ingredientes de sub-recetas se migrarían.`);

    // Estimar cuántos receta_ingredientes se actualizarían
    const est = await pg.query(`
      SELECT COUNT(*) as c
      FROM receta_ingredientes ri
      INNER JOIN productos p ON p.id = ri.producto_id
      WHERE (p.codigo ILIKE 'PL%' OR p.nombre ILIKE 'SUBR%')
        AND ri.sub_receta_id IS NULL
    `);
    log(`      (dry-run) ~${est.rows[0].c} receta_ingredientes pasarían a usar sub_receta_id.`);
  }

  // ── Resumen ────────────────────────────────────────────────────────────────
  log('');
  log('================================================');
  log('RESUMEN:');
  log(`  Sub-recetas detectadas:       ${subRows.length}`);
  log(`  Ingredientes de sub-recetas:  ${subIngrRows.length}`);
  if (!DRY_RUN) {
    log(`  Verificando estado final...`);
    const fin = await pg.query(
      "SELECT COUNT(*) as total FROM recetas WHERE tipo_receta = 'sub_receta'"
    );
    const finIngr = await pg.query(
      "SELECT COUNT(*) as total FROM receta_ingredientes WHERE sub_receta_id IS NOT NULL"
    );
    log(`  Recetas sub_receta en PG:     ${fin.rows[0].total}`);
    log(`  Ingredientes con sub_receta_id: ${finIngr.rows[0].total}`);
  }
  log(DRY_RUN ? '\nDRY-RUN OK. Corre sin --dry-run para migrar.' : '\n✓ Sync completado.');
  log('================================================');

  pg.release();
  await pool.end();
  await sqlPool.close();
}

main().catch(err => {
  console.error('\n❌ ERROR:', err.message, err.stack);
  process.exit(1);
});
