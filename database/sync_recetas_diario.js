/**
 * sync_recetas_diario.js
 *
 * Sincronización diaria Brilo (SQL Server) → RDS (compras_db)
 *
 * Reglas por categoría:
 *   AMBOS + mod_local=false → refresca ingredientes + actualiza activa/estado + mod_local=false + sincronizado_brilo=true
 *   AMBOS + mod_local=true  → solo actualiza activa + sincronizado_brilo=true (respeta cambios locales)
 *   SOLO BRILO activa       → crea receta + ingredientes en RDS
 *   SOLO BRILO inactiva + en menú → crea receta inactiva en RDS
 *   SOLO BRILO inactiva sin menú  → omite
 *   SOLO RDS                → no tocar (creadas localmente)
 *
 * Uso:
 *   node sync_recetas_diario.js             (ejecuta)
 *   node sync_recetas_diario.js --dry-run   (muestra qué haría, sin cambios)
 */

const sql      = require('mssql');
const { Pool } = require('pg');

// ── Conexiones ────────────────────────────────────────────────────────────────
const SQL_CFG = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 20000 },
};
const PG_CFG = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'compras_db', user: 'cadejo_admin', password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
  connectionTimeoutMillis: 30000, idleTimeoutMillis: 90000,
};

// ── Prefijos de receta ────────────────────────────────────────────────────────
const PREFIJOS_RECETA = ['PL', 'SUBR', 'PR', 'PRD', 'LLPL', 'HZPL', 'HZPR', 'EVPL', 'VOPL', 'PMPL'];
const esCodReceta = cod => cod && PREFIJOS_RECETA.some(p => String(cod).startsWith(p));
const esSubReceta = (cod, tipo) => {
  const t = (tipo || '').toLowerCase();
  return t.includes('sub') || t.includes('subr') || String(cod).toUpperCase().startsWith('SUBR');
};

// ── Helpers ───────────────────────────────────────────────────────────────────
const DRY_RUN = process.argv.includes('--dry-run');
const BATCH   = 50;
const NOW     = new Date().toISOString();
const AUD     = 'sync_recetas_diario';
const ts      = () => new Date().toTimeString().slice(0, 8);
const log     = s  => console.log(`[${ts()}] ${s}`);
const clean   = (s, max = 150) => !s ? '' : String(s).trim().replace(/\s+/g, ' ').slice(0, max);

const UNIT_MAP = {
  'ONZAS': 'oz', 'ONZA': 'oz',
  'ONZAS FLUIDAS': 'oz fl', 'ONZA FLUIDA': 'oz fl',
  'UNIDAD': 'u', 'UNIDADES': 'u',
  'LIBRA': 'lb', 'LIBRAS': 'lb',
  'LITRO': 'lt', 'LITROS': 'lt',
  'MILILITRO': 'ml', 'MILILITROS': 'ml',
  'KILOGRAMO': 'kg', 'KILOGRAMOS': 'kg',
  'GRAMO': 'g', 'GRAMOS': 'g',
  'BARRIL': 'barril',
  'BOTELLA': 'botella', 'BOTELLA 0.75 LT': 'botella', 'BOTELLA 0.70 LT': 'botella',
  'PORCION': 'porcion', 'PORCIÓN': 'porcion',
  'REBANADA': 'rebanada',
  'CAJA': 'caja',
  'PAQUETE': 'paquete',
  'GALON': 'galon', 'GALÓN': 'galon',
  'TANDA': 'tanda',
  'BOLSA 2 KG': 'bolsa 2kg', 'BOLSA 1 KG': 'bolsa 1kg', 'BOLSA 5LB': 'bolsa 5lb',
};
const normUnit = s => !s ? 'u' : (UNIT_MAP[s.trim().toUpperCase()] ?? s.trim().toLowerCase().slice(0, 20));

// ── Main ──────────────────────────────────────────────────────────────────────
async function main() {
  log('════════════════════════════════════════════════════');
  log('SYNC RECETAS DIARIO: Brilo → RDS');
  log(DRY_RUN ? '*** DRY RUN — sin cambios ***' : '*** MODO REAL ***');
  log('════════════════════════════════════════════════════\n');

  // ── Conectar ──────────────────────────────────────────────────────────────
  const sqlPool = await sql.connect(SQL_CFG);
  log('SQL Server OK');
  const pgPool = new Pool(PG_CFG);
  const pg     = await pgPool.connect();
  log('PostgreSQL OK\n');

  try {
    // ═══════════════════════════════════════════════════════════════
    // FASE 1 — Cargar datos de Brilo
    // ═══════════════════════════════════════════════════════════════
    log('[1/5] Leyendo Brilo...');

    // Cargar categorías válidas de RDS para filtrar productos de Brilo
    const { rows: catRows2 } = await pg.query(`SELECT nombre FROM receta_categorias WHERE activa = true`);
    const catValidas = catRows2.map(r => r.nombre);
    // Sub-Recetas también es válida en Brilo aunque no exista como receta_categoria en RDS
    const catValidasSet = new Set([...catValidas, 'Sub-Recetas', 'Sub-Receta', 'Sub Recetas']);

    // 1a. Todas las recetas de Brilo — solo de categorías que corresponden a recetas reales
    const bRec = (await sqlPool.request().query(`
      SELECT p.proId, TRIM(p.proCodigo) AS codigo, p.proNombre AS nombre,
             p.proActivo AS activo, cpr.cprCodigo AS cat_codigo, cpr.cprNombre AS cat_nombre
      FROM olComun.dbo.Productos p WITH(NOLOCK)
      INNER JOIN olComun.dbo.CategoriasProductos cpr WITH(NOLOCK) ON cpr.cprId = p.cprId
      WHERE p.proCodigo IS NOT NULL AND LTRIM(RTRIM(p.proCodigo)) != ''
        AND (p.proCodigo LIKE 'PL%'   OR p.proCodigo LIKE 'SUBR%'
          OR p.proCodigo LIKE 'PR%'   OR p.proCodigo LIKE 'PRD%'
          OR p.proCodigo LIKE 'LLPL%' OR p.proCodigo LIKE 'HZPL%'
          OR p.proCodigo LIKE 'HZPR%' OR p.proCodigo LIKE 'EVPL%'
          OR p.proCodigo LIKE 'VOPL%' OR p.proCodigo LIKE 'PMPL%')
      ORDER BY p.proCodigo
    `)).recordset.filter(r => catValidasSet.has(r.cat_nombre));

    const briloMap = {};
    bRec.forEach(r => { briloMap[r.codigo] = r; });
    log(`  Brilo: ${bRec.length} recetas (activas + inactivas)`);

    // 1b. Menú por sucursal (sucId directo → se usa como sucursal_id en RDS)
    const bMenu = (await sqlPool.request().query(`
      SELECT DISTINCT TRIM(p.proCodigo) AS codigo, px.sucId
      FROM olRestaurante.dbo.ProductoXCocinaXSucRst px WITH(NOLOCK)
      JOIN olComun.dbo.Productos p WITH(NOLOCK) ON p.proId = px.proId
      WHERE p.proCodigo IS NOT NULL
    `)).recordset;

    const menuMap = {}; // codigo → Set<sucId>
    bMenu.forEach(r => {
      if (!menuMap[r.codigo]) menuMap[r.codigo] = new Set();
      menuMap[r.codigo].add(r.sucId);
    });
    log(`  Brilo: ${bRec.filter(r => menuMap[r.codigo]).length} recetas en menú`);

    // 1c. Ingredientes de todas las recetas de Brilo
    log('  Leyendo ingredientes Brilo...');
    const proIds = bRec.map(r => r.proId).join(',');
    const bIng = (await sqlPool.request().query(`
      SELECT mx.proId AS rec_proId, mx.proIdMaterial AS mat_proId,
             TRIM(mat.proCodigo) AS ing_codigo, mat.proNombre AS ing_nombre,
             cpr.cprCodigo AS ing_cat_codigo, cpr.cprNombre AS ing_cat_nombre,
             mx.mxprCantidad AS cantidad,
             ISNULL(uni.uniNombre, 'u') AS unidad
      FROM olComun.dbo.MaterialesXProducto mx WITH(NOLOCK)
      JOIN olComun.dbo.Productos mat WITH(NOLOCK) ON mat.proId = mx.proIdMaterial
      LEFT JOIN olComun.dbo.CategoriasProductos cpr WITH(NOLOCK) ON cpr.cprId = mat.cprId
      LEFT JOIN olComun.dbo.Unidades uni WITH(NOLOCK) ON uni.uniId = mx.uniId
      WHERE mx.proId IN (${proIds})
        AND mx.mxprActivo = 1 AND mx.mxprEliminado = 0
        AND mx.mxprCantidad > 0
        AND mat.proCodigo IS NOT NULL
      ORDER BY mx.proId, mx.mxprId
    `)).recordset;

    // Map proId → [ ingredientes ]
    const briloIngMap = {};
    bIng.forEach(r => {
      if (!briloIngMap[r.rec_proId]) briloIngMap[r.rec_proId] = [];
      briloIngMap[r.rec_proId].push(r);
    });
    log(`  Brilo: ${bIng.length} líneas de ingredientes`);

    // ═══════════════════════════════════════════════════════════════
    // FASE 2 — Cargar datos de RDS
    // ═══════════════════════════════════════════════════════════════
    log('\n[2/5] Leyendo RDS...');

    const rdsRec = (await pg.query(`
      SELECT r.id, r.codigo_origen, r.nombre, r.activa, r.estado_id,
             r.modificado_localmente, r.tipo_receta, r.sincronizado_brilo
      FROM recetas r
      WHERE r.codigo_origen IS NOT NULL AND r.codigo_origen != ''
      ORDER BY r.codigo_origen
    `)).rows;

    const rdsMap = {}; // codigo_origen → row
    rdsRec.forEach(r => { rdsMap[r.codigo_origen] = r; });
    log(`  RDS: ${rdsRec.length} recetas con codigo_origen`);

    // Mapa de productos por codigo
    const prodRows = (await pg.query(`SELECT id, codigo FROM productos WHERE activo = true`)).rows;
    const prodMap  = {};
    prodRows.forEach(r => { prodMap[r.codigo] = r.id; });

    // Mapa de categorias
    const catRows = (await pg.query(`SELECT id, key FROM categorias`)).rows;
    const catKeyMap = {};
    catRows.forEach(r => { catKeyMap[r.key] = r.id; });
    const fallbackCatId = catRows.find(r => r.key === 'SIN-CAT')?.id ?? catRows[0]?.id ?? null;

    // receta_sucursal existentes
    const rsRows = (await pg.query(`SELECT receta_id, sucursal_id FROM receta_sucursal WHERE activa = true`)).rows;
    const rsSet  = new Set(rsRows.map(r => `${r.receta_id}:${r.sucursal_id}`));

    // ═══════════════════════════════════════════════════════════════
    // FASE 3 — Categorizar
    // ═══════════════════════════════════════════════════════════════
    log('\n[3/5] Categorizando...');

    const paraInsertar  = []; // { brilo record }
    const paraUpdate    = []; // { brilo record, rds record, mod_local }
    const paraActivo    = []; // { brilo record, rds record } solo actualizar activa + flag
    const menuSync      = []; // { receta_id, sucId } a insertar en receta_sucursal

    for (const cod of Object.keys(briloMap)) {
      const b = briloMap[cod];
      const r = rdsMap[cod];
      const enMenu = !!menuMap[cod];

      if (r) {
        // Ya existe en RDS
        if (r.modificado_localmente) {
          paraActivo.push({ b, r });         // mod_local=true → solo flags
        } else {
          paraUpdate.push({ b, r });         // mod_local=false → refresh completo
        }
      } else {
        // No existe en RDS
        if (b.activo || enMenu) {
          paraInsertar.push(b);              // activa o en menú → importar
        }
        // inactiva sin menú → omitir
      }
    }

    log(`  Para insertar (nuevas):            ${paraInsertar.length}`);
    log(`  Para actualizar completo:          ${paraUpdate.length}`);
    log(`  Para actualizar solo flags:        ${paraActivo.length}`);
    log(`  Omitidas (inactivas sin menú):     ${Object.keys(briloMap).length - paraInsertar.length - paraUpdate.length - paraActivo.length}`);

    if (DRY_RUN) {
      const actMenu   = paraInsertar.filter(b => b.activo && menuMap[b.codigo]);
      const actNoMenu = paraInsertar.filter(b => b.activo && !menuMap[b.codigo]);
      const inaMenu   = paraInsertar.filter(b => !b.activo && menuMap[b.codigo]);

      log('\n══ NUEVAS A CREAR ══════════════════════════════');
      log(`  Activas + en menú:   ${actMenu.length}`);
      log(`  Activas + sin menú:  ${actNoMenu.length}`);
      log(`  Inactivas + en menú: ${inaMenu.length}`);

      log('\n══ ACTUALIZACIONES COMPLETAS (mod_local=false) ══');
      log(`  Total: ${paraUpdate.length}`);
      log(`  Con ingredientes en Brilo: ${paraUpdate.filter(({b}) => (briloIngMap[b.proId]||[]).length > 0).length}`);

      log('\n══ SOLO FLAGS (mod_local=true) ══════════════════');
      log(`  Total: ${paraActivo.length}`);
      paraActivo.slice(0, 10).forEach(({ b }) =>
        log(`    ${b.codigo.padEnd(18)} ${b.nombre.slice(0, 40)}`));
      if (paraActivo.length > 10) log(`    ... y ${paraActivo.length - 10} más`);

      log('\n════ DRY-RUN COMPLETO — ejecuta sin --dry-run para aplicar ════');
      return;
    }

    // ═══════════════════════════════════════════════════════════════
    // FASE 4 — Ejecutar cambios
    // ═══════════════════════════════════════════════════════════════
    log('\n[4/5] Aplicando cambios...');

    // ── 4a. Insertar recetas nuevas ──────────────────────────────
    log('\n  [4a] Insertando recetas nuevas...');
    const nuevaMap = {}; // codigo → pg id
    let insOk = 0;

    for (let i = 0; i < paraInsertar.length; i += BATCH) {
      const chunk  = paraInsertar.slice(i, i + BATCH);
      const params = [], parts = [];

      chunk.forEach(b => {
        const tipoRec  = esSubReceta(b.codigo, b.cat_nombre) ? 'sub_receta' : 'plato';
        const estadoId = b.activo ? 4 : 5;
        const catKey   = b.cat_codigo ? String(b.cat_codigo).replace(/[^a-zA-Z0-9\-_]/g, '-') : null;
        const catId    = (catKey && catKeyMap[catKey]) ? catKeyMap[catKey] : fallbackCatId;

        const vals = [
          clean(b.nombre, 150),  // nombre
          clean(b.codigo, 50),   // codigo_origen
          clean(b.cat_nombre, 80) || 'General', // tipo
          tipoRec,               // tipo_receta
          catId,                 // categoria_id
          b.activo ? true : false, // activa
          estadoId,              // estado_id
          false,                 // modificado_localmente
          true,                  // sincronizado_brilo
          AUD, NOW, NOW,
        ];
        const ph = vals.map(v => { params.push(v); return `$${params.length}`; });
        parts.push(`(${ph.join(',')})`);
      });

      const res = await pg.query(
        `INSERT INTO recetas
           (nombre, codigo_origen, tipo, tipo_receta, categoria_id, activa, estado_id,
            modificado_localmente, sincronizado_brilo, aud_usuario, created_at, updated_at)
         VALUES ${parts.join(',')}
         ON CONFLICT (codigo_origen) DO NOTHING
         RETURNING id, codigo_origen`,
        params
      );
      res.rows.forEach(row => { nuevaMap[row.codigo_origen] = row.id; });
      insOk += res.rows.length;
      process.stdout.write(`\r    Insertadas: ${i + chunk.length}/${paraInsertar.length}  `);
    }
    console.log();
    log(`  Recetas nuevas insertadas: ${insOk}`);

    // ── 4b. Actualizar completo (mod_local=false): ingredientes + flags ──
    log('\n  [4b] Actualizando recetas existentes (mod_local=false)...');
    let updOk = 0;

    for (let i = 0; i < paraUpdate.length; i += BATCH) {
      const chunk = paraUpdate.slice(i, i + BATCH);
      for (const { b, r } of chunk) {
        const estadoId  = r.estado_id;
        const activaRds = r.activa;

        // Brilo solo puede DESACTIVAR, nunca reactivar lo que está inactivo en RDS.
        // Si RDS tiene activa=false y Brilo dice activa=true → respetar RDS (quedarse inactiva).
        // Si Brilo dice activa=false → desactivar en RDS sin importar el estado local.
        const nuevaActiva = !b.activo ? false : activaRds;

        // Estado: solo promover Autorizada(3)→Activa(4) si la receta sigue activa en RDS.
        // Nunca promover desde Inactiva(5) — eso fue una desactivación deliberada.
        const nuevoEstado = nuevaActiva && estadoId === 3 ? 4 : estadoId;

        await pg.query(
          `UPDATE recetas SET
             activa                = $1,
             estado_id             = $2,
             modificado_localmente = false,
             sincronizado_brilo    = true,
             updated_at            = $3
           WHERE id = $4`,
          [nuevaActiva, nuevoEstado, NOW, r.id]
        );

        // Eliminar ingredientes viejos y reinsertar desde Brilo
        // Opción B: actualizar/insertar sin borrar — ingredientes removidos de Brilo se dejan intactos
        const bIngs = briloIngMap[b.proId] || [];
        for (let j = 0; j < bIngs.length; j += 100) {
          const ingChunk = bIngs.slice(j, j + 100);
          const iParams  = [], iParts = [];
          for (const ing of ingChunk) {
            const pId = prodMap[ing.ing_codigo];
            if (!pId) continue; // producto no existe en RDS — se inserta más adelante
            const vals = [r.id, pId, parseFloat(ing.cantidad) || 0, normUnit(ing.unidad), AUD, NOW, NOW];
            const ph   = vals.map(v => { iParams.push(v); return `$${iParams.length}`; });
            iParts.push(`(${ph.join(',')})`);
          }
          if (iParts.length) {
            await pg.query(
              `INSERT INTO receta_ingredientes
                 (receta_id, producto_id, cantidad_por_plato, unidad, aud_usuario, created_at, updated_at)
               VALUES ${iParts.join(',')}
               ON CONFLICT (receta_id, producto_id) DO UPDATE SET
                 cantidad_por_plato = EXCLUDED.cantidad_por_plato,
                 unidad             = EXCLUDED.unidad,
                 updated_at         = EXCLUDED.updated_at`,
              iParams
            );
          }
        }
        updOk++;
      }
      process.stdout.write(`\r    Actualizadas: ${i + chunk.length}/${paraUpdate.length}  `);
    }
    console.log();
    log(`  Recetas actualizadas: ${updOk}`);

    // ── 4c. Solo flags (mod_local=true): activa + sincronizado_brilo ──
    log('\n  [4c] Actualizando flags (mod_local=true)...');
    let flagOk = 0;

    for (let i = 0; i < paraActivo.length; i += BATCH) {
      const chunk = paraActivo.slice(i, i + BATCH);

      // Brilo solo puede DESACTIVAR — si Brilo está inactiva, desactiva en RDS.
      // Si Brilo está activa pero RDS ya está inactiva, respetar RDS (no reactivar).
      const idsBriloInactiva = chunk.filter(({ b }) => !b.activo).map(({ r }) => r.id);

      if (idsBriloInactiva.length) {
        await pg.query(
          `UPDATE recetas SET activa = false, sincronizado_brilo = true, updated_at = $1 WHERE id = ANY($2)`,
          [NOW, idsBriloInactiva]
        );
      }
      // Para las que Brilo está activa: solo actualizamos sincronizado_brilo, sin tocar activa
      const idsBriloActiva = chunk.filter(({ b }) => b.activo).map(({ r }) => r.id);
      if (idsBriloActiva.length) {
        await pg.query(
          `UPDATE recetas SET sincronizado_brilo = true, updated_at = $1 WHERE id = ANY($2)`,
          [NOW, idsBriloActiva]
        );
      }
      flagOk += chunk.length;
      process.stdout.write(`\r    Flags: ${i + chunk.length}/${paraActivo.length}  `);
    }
    console.log();
    log(`  Flags actualizados: ${flagOk}`);

    // ── 4d. Ingredientes de recetas nuevas ───────────────────────
    log('\n  [4d] Insertando ingredientes de recetas nuevas...');

    // Recargar prodMap para incluir productos recién sincronizados
    const prodRefresh = (await pg.query(`SELECT id, codigo FROM productos WHERE activo = true`)).rows;
    prodRefresh.forEach(r => { prodMap[r.codigo] = r.id; });

    // Unir nuevaMap con rdsMap para tener IDs completos
    const allNewIds = { ...nuevaMap };

    let ingOk = 0, ingSkip = 0;

    for (const b of paraInsertar) {
      const recId = allNewIds[b.codigo];
      if (!recId) { ingSkip++; continue; }

      const bIngs = briloIngMap[b.proId] || [];
      if (!bIngs.length) continue;

      const iParams = [], iParts = [];
      for (const ing of bIngs) {
        const pId = prodMap[ing.ing_codigo];
        if (!pId) { ingSkip++; continue; }
        const vals = [recId, pId, parseFloat(ing.cantidad) || 0, normUnit(ing.unidad), AUD, NOW, NOW];
        const ph   = vals.map(v => { iParams.push(v); return `$${iParams.length}`; });
        iParts.push(`(${ph.join(',')})`);
        ingOk++;
      }
      if (iParts.length) {
        await pg.query(
          `INSERT INTO receta_ingredientes
             (receta_id, producto_id, cantidad_por_plato, unidad, aud_usuario, created_at, updated_at)
           VALUES ${iParts.join(',')}
           ON CONFLICT (receta_id, producto_id) DO NOTHING`,
          iParams
        );
      }
    }
    log(`  Ingredientes nuevos: ${ingOk} insertados, ${ingSkip} saltados (producto no en RDS)`);

    // ── 4e. Corregir sub_receta_id ───────────────────────────────
    log('\n  [4e] Reconectando sub_receta_id...');
    const fixRes = await pg.query(`
      UPDATE receta_ingredientes ri
      SET sub_receta_id = sr.id,
          producto_id   = NULL,
          updated_at    = $1
      FROM productos p
      JOIN recetas sr ON sr.codigo_origen = p.codigo AND sr.tipo_receta = 'sub_receta'
      WHERE ri.producto_id = p.id
        AND ri.sub_receta_id IS NULL
    `, [NOW]);
    log(`  sub_receta_id reconectados: ${fixRes.rowCount}`);

    // ── 4f. Sincronizar menú (receta_sucursal) — SOLO para recetas NUEVAS ───────
    // Las recetas que ya existían en RDS conservan sus asignaciones de sucursal.
    // Solo las recetas recién insertadas (nuevaMap) reciben las asignaciones de Brilo.
    log('\n  [4f] Sincronizando menú (receta_sucursal) — solo recetas nuevas...');
    let menuOk = 0;

    for (const [cod, sucIds] of Object.entries(menuMap)) {
      if (!esCodReceta(cod)) continue;
      const recId = nuevaMap[cod]; // solo recetas insertadas en esta ejecución
      if (!recId) continue;        // si no es nueva, no tocar su receta_sucursal

      for (const sucId of sucIds) {
        const key = `${recId}:${sucId}`;
        if (rsSet.has(key)) continue;

        await pg.query(
          `INSERT INTO receta_sucursal (receta_id, sucursal_id, platos_semana, activa, aud_usuario, created_at, updated_at)
           VALUES ($1, $2, 0, true, $3, $4, $4)
           ON CONFLICT (receta_id, sucursal_id) DO NOTHING`,
          [recId, sucId, AUD, NOW]
        ).catch(() => {});
        menuOk++;
      }
    }
    log(`  receta_sucursal insertadas (solo recetas nuevas): ${menuOk}`);

    // ═══════════════════════════════════════════════════════════════
    // FASE 5 — Resumen
    // ═══════════════════════════════════════════════════════════════
    const totalRec = (await pg.query(`SELECT COUNT(*) FROM recetas WHERE activa = true AND estado_id IN (3,4)`)).rows[0].count;

    log('\n[5/5] ════════════════════════════════════════════════');
    log('RESUMEN FINAL');
    log(`  Recetas nuevas creadas:       ${insOk}`);
    log(`  Recetas actualizadas:         ${updOk}`);
    log(`  Flags actualizados:           ${flagOk}`);
    log(`  Ingredientes nuevos:          ${ingOk}`);
    log(`  sub_receta_id reconectados:   ${fixRes.rowCount}`);
    log(`  Menú (receta_sucursal):       ${menuOk}`);
    log(`  Total activas en RDS ahora:   ${totalRec}`);
    log('════════════════════════════════════════════════════');

  } finally {
    pg.release();
    await pgPool.end();
    await sqlPool.close();
    log('Conexiones cerradas.');
  }
}

main().catch(err => {
  console.error('\nERROR:', err.message ?? err);
  process.exit(1);
});
