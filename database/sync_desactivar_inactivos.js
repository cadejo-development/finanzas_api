/**
 * sync_desactivar_inactivos.js
 *
 * Desactiva en PostgreSQL las recetas que ya no tienen botón activo
 * en ningún menú del POS (olRestaurante), respetando:
 *   - recetas con modificado_localmente = true
 *   - recetas creadas localmente (codigo_origen IS NULL)
 *   - recetas que son ingredientes de las anteriores
 *   - recetas cuyos productos son ingredientes de platos activos en POS
 *
 * Uso:
 *   node sync_desactivar_inactivos.js          → dry-run (solo imprime)
 *   node sync_desactivar_inactivos.js --apply  → aplica los cambios
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const DRY_RUN = !process.argv.includes('--apply');

// ── Conexiones ────────────────────────────────────────────────────────────────
const cfgRst = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olRestaurante',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};
const cfgCom = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olComun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};
const pgConfig = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432, database: 'compras_db',
  user: 'cadejo_admin', password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
  connectionTimeoutMillis: 30000,
};

const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s => console.log(`[${ts()}] ${s}`);
const hr  = () => console.log('─'.repeat(70));

// ── Paso 1: productos con botón activo en menú activo (olRestaurante) ─────────
async function getActivePosProductos(poolRst) {
  log('SQL Server olRestaurante → productos con botón activo en menú activo...');
  const r = await poolRst.request().query(`
    SELECT DISTINCT BTN.proId
    FROM   olRestaurante.dbo.detMenusRst  DET WITH (NOLOCK)
    INNER JOIN olRestaurante.dbo.maeMenusRst MNU WITH (NOLOCK) ON MNU.mmnrstId = DET.mmnrstId
    INNER JOIN olRestaurante.dbo.BotonesRst  BTN WITH (NOLOCK) ON BTN.btnrstId = DET.btnrstId
    WHERE  DET.dmnrstEliminado = 0
      AND  BTN.btnrstEliminado = 0
      AND  MNU.mmnrstActivo    = 1
      AND  MNU.mmnrstEliminado = 0
      AND  BTN.proId IS NOT NULL
  `);
  const ids = r.recordset.map(row => row.proId);
  log(`  → ${ids.length} productos con botón POS activo`);
  return ids;
}

// ── Paso 2: códigos de esos productos (proId → proCodigo) ──────────────────
async function getCodigosByIds(poolCom, proIds) {
  if (proIds.length === 0) return [];
  const chunks = [];
  for (let i = 0; i < proIds.length; i += 500) chunks.push(proIds.slice(i, i + 500));
  const codigos = new Set();
  for (const chunk of chunks) {
    const r = await poolCom.request().query(`
      SELECT proCodigo FROM olComun.dbo.Productos WITH (NOLOCK)
      WHERE proId IN (${chunk.join(',')})
    `);
    r.recordset.forEach(row => { if (row.proCodigo) codigos.add(row.proCodigo.trim()); });
  }
  return [...codigos];
}

// ── Paso 3: ingredientes (materias primas / sub-recetas) de esos platos ───────
async function getIngredientesDePlatos(poolCom, proIds) {
  if (proIds.length === 0) return [];
  log('SQL Server olComun → ingredientes de los platos activos...');
  const chunks = [];
  for (let i = 0; i < proIds.length; i += 500) chunks.push(proIds.slice(i, i + 500));
  const ingCodigos = new Set();
  for (const chunk of chunks) {
    const r = await poolCom.request().query(`
      SELECT DISTINCT PROM.proCodigo AS codigo
      FROM olComun.dbo.MaterialesXProducto MXP WITH (NOLOCK)
      INNER JOIN olComun.dbo.Productos PROM WITH (NOLOCK) ON PROM.proId = MXP.proIdMaterial
      WHERE MXP.proId IN (${chunk.join(',')})
        AND MXP.mxprEliminado = 0
        AND PROM.proCodigo IS NOT NULL
    `);
    r.recordset.forEach(row => { if (row.codigo) ingCodigos.add(row.codigo.trim()); });
  }
  log(`  → ${ingCodigos.size} ingredientes de platos activos`);
  return [...ingCodigos];
}

// ── Paso 4: recetas protegidas en PostgreSQL ──────────────────────────────────
async function getProtectedPG(pg) {
  log('PostgreSQL → recetas protegidas (modificado_localmente o sin codigo_origen)...');
  const r = await pg.query(`
    SELECT id, codigo_origen, nombre, tipo_receta
    FROM   recetas
    WHERE  modificado_localmente = true
       OR  codigo_origen IS NULL
  `);
  log(`  → ${r.rows.length} recetas protegidas localmente`);
  return r.rows;
}

// ── Paso 5: ingredientes de recetas protegidas (en PG) ────────────────────────
async function getIngredientesDeProtegidas(pg, protectedIds) {
  if (protectedIds.length === 0) return [];
  log('PostgreSQL → ingredientes de recetas protegidas...');
  const r = await pg.query(`
    SELECT DISTINCT r.id, r.codigo_origen, r.nombre
    FROM   receta_ingredientes ri
    INNER JOIN recetas r ON r.id = ri.sub_receta_id
    WHERE  ri.receta_id = ANY($1)
      AND  ri.sub_receta_id IS NOT NULL
      AND  r.codigo_origen IS NOT NULL
  `, [protectedIds]);
  log(`  → ${r.rows.length} ingredientes de recetas protegidas`);
  return r.rows;
}

// ── Paso 6: todas las recetas activas en PG ───────────────────────────────────
async function getAllActivePG(pg) {
  const r = await pg.query(`
    SELECT id, codigo_origen, nombre, tipo_receta, modificado_localmente
    FROM   recetas
    WHERE  activa = true
      AND  codigo_origen IS NOT NULL
  `);
  return r.rows;
}

// ─────────────────────────────────────────────────────────────────────────────
async function main() {
  hr();
  log(DRY_RUN
    ? 'MODO DRY-RUN — ningún cambio será aplicado'
    : 'MODO APPLY — se aplicarán los cambios en PostgreSQL');
  hr();

  // Conectar
  log('Conectando SQL Server (olRestaurante)...');
  const poolRst = await sql.connect(cfgRst);

  log('Conectando SQL Server (olComun)...');
  const poolCom = await new (require('mssql').ConnectionPool)(cfgCom).connect();

  log('Conectando PostgreSQL...');
  const pool = new Pool(pgConfig);
  const pg   = await pool.connect();
  log('Conexiones OK');
  hr();

  // ── PASO 1-3: construir el Protected Set desde SQL Server ─────────────────
  const activePosIds     = await getActivePosProductos(poolRst);
  const activeCodigos    = await getCodigosByIds(poolCom, activePosIds);
  const ingCodigos       = await getIngredientesDePlatos(poolCom, activePosIds);

  const activePosSet = new Set([...activeCodigos, ...ingCodigos]);
  log(`Total códigos protegidos vía SQL Server: ${activePosSet.size}`);
  hr();

  // ── PASO 4-5: construir el Protected Set desde PostgreSQL ─────────────────
  const protectedPG        = await getProtectedPG(pg);
  const protectedPGIds     = protectedPG.map(r => r.id);
  const ingDeProtegidas    = await getIngredientesDeProtegidas(pg, protectedPGIds);

  const protectedPGCodigos = new Set([
    ...protectedPG.filter(r => r.codigo_origen).map(r => r.codigo_origen.trim()),
    ...ingDeProtegidas.map(r => r.codigo_origen.trim()),
  ]);
  log(`Total códigos protegidos vía PostgreSQL local: ${protectedPGCodigos.size}`);
  hr();

  // ── PASO 6: calcular candidatas a desactivar ──────────────────────────────
  log('PostgreSQL → cargando recetas activas con codigo_origen...');
  const allActive = await getAllActivePG(pg);
  log(`  → ${allActive.length} recetas activas con origen en SQL Server`);

  const toDeactivate = allActive.filter(r => {
    const cod = (r.codigo_origen ?? '').trim();
    return !activePosSet.has(cod) && !protectedPGCodigos.has(cod);
  });

  hr();
  log(`RESUMEN:`);
  log(`  Recetas activas totales (con codigo_origen): ${allActive.length}`);
  log(`  Protegidas por POS activo:                   ${allActive.filter(r => activePosSet.has((r.codigo_origen||'').trim())).length}`);
  log(`  Protegidas localmente (mod/local/ingrediente):${allActive.filter(r => protectedPGCodigos.has((r.codigo_origen||'').trim())).length}`);
  log(`  CANDIDATAS A DESACTIVAR:                     ${toDeactivate.length}`);
  hr();

  if (toDeactivate.length > 0) {
    // Agrupar por tipo para visualizar
    const porTipo = {};
    toDeactivate.forEach(r => {
      const t = r.tipo_receta || 'receta';
      if (!porTipo[t]) porTipo[t] = [];
      porTipo[t].push(r);
    });

    Object.entries(porTipo).forEach(([tipo, rows]) => {
      console.log(`\n[${tipo.toUpperCase()}] — ${rows.length} registros:`);
      rows.slice(0, 20).forEach(r => console.log(`  - ${String(r.codigo_origen||'').padEnd(16)} ${r.nombre}`));
      if (rows.length > 20) console.log(`  ... y ${rows.length - 20} más`);
    });
  }

  hr();

  if (DRY_RUN) {
    log('DRY-RUN completado. Para aplicar: node sync_desactivar_inactivos.js --apply');
    pg.release();
    await pool.end();
    await poolRst.close();
    return;
  }

  // ── APPLY ─────────────────────────────────────────────────────────────────
  if (toDeactivate.length === 0) {
    log('Nada que desactivar. Saliendo.');
    pg.release();
    await pool.end();
    await poolRst.close();
    return;
  }

  const ids = toDeactivate.map(r => r.id);

  await pg.query('BEGIN');
  try {
    // Desactivar en receta_sucursal
    const rs = await pg.query(
      `UPDATE receta_sucursal SET activa = false WHERE receta_id = ANY($1) AND activa = true`,
      [ids]
    );
    log(`receta_sucursal → ${rs.rowCount} filas desactivadas`);

    // Desactivar en recetas
    const rr = await pg.query(
      `UPDATE recetas SET activa = false, updated_at = NOW() WHERE id = ANY($1) AND activa = true`,
      [ids]
    );
    log(`recetas         → ${rr.rowCount} filas desactivadas`);

    await pg.query('COMMIT');
    log('COMMIT OK');
  } catch (e) {
    await pg.query('ROLLBACK');
    log('ERROR — ROLLBACK ejecutado');
    throw e;
  }

  hr();
  log('Proceso completado.');
  pg.release();
  await pool.end();
  await poolRst.close();
}

main().catch(e => { console.error('\nERROR FATAL:', e.message); process.exit(1); });
