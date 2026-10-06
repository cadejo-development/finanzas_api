require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

/**
 * sync_brilo_snapshot.js
 *
 * Captura el estado actual de Brilo (SQL Server) en tablas snapshot de RDS.
 * Corre diariamente (mismo horario que sync_recetas_diario.js o justo antes).
 *
 * Tablas destino: brilo_snapshot_recetas, brilo_snapshot_ingredientes
 *
 * Captura por receta:
 *   - código, nombre, categoría, precio, activo
 *   - proNoEnviarACocinaRST → no_enviar_cocina
 *   - tiene filas en ProductoXCocinaXSucRst → en_boton_cocina
 *   - sucursales donde está en menú (con cocina asignada)
 *   - ingredientes: cantidad_base, unidad_base, cantidad_pres, unidad_pres
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const SQL_CFG = {
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_PASSWORD_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 20000 },
};
const PG_CFG = {
  host: process.env.DB_HOST_COMPRAS || process.env.DB_HOST, port: 5432,
  database: process.env.DB_DATABASE_COMPRAS || 'gestion_operaciones_db',
  user: process.env.DB_USERNAME_COMPRAS || process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD_COMPRAS || process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
};

const PREFIJOS = ['PL', 'SUBR', 'PR', 'PRD', 'LLPL', 'HZPL', 'HZPR', 'EVPL', 'VOPL', 'PMPL'];
const esReceta = cod => cod && PREFIJOS.some(p => String(cod).startsWith(p));
const esSubRec = (cod, catNombre) => {
  const t = (catNombre || '').toLowerCase();
  return t.includes('sub') || String(cod).toUpperCase().startsWith('SUBR');
};

const NOW = new Date().toISOString();
const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s  => console.log(`[${ts()}] ${s}`);

async function main() {
  log('════════════════════════════════════════════');
  log('SYNC BRILO SNAPSHOT: Brilo → RDS');
  log('════════════════════════════════════════════\n');

  const sqlPool = await sql.connect(SQL_CFG);
  log('SQL Server OK');
  const pgPool  = new Pool(PG_CFG);
  const pg      = await pgPool.connect();
  log('PostgreSQL OK\n');

  try {
    // ── 1. Recetas de Brilo ─────────────────────────────────────────────────
    log('[1/4] Leyendo recetas de Brilo...');
    const recResult = await sqlPool.request().query(`
      SELECT
        p.proId,
        TRIM(p.proCodigo)    AS codigo,
        p.proNombre          AS nombre,
        p.proActivo          AS activo,
        p.proPrecio          AS precio,
        p.proNoEnviarACocinaRST AS no_enviar_cocina,
        cpr.cprCodigo        AS cat_codigo,
        cpr.cprNombre        AS cat_nombre
      FROM olComun.dbo.Productos p WITH(NOLOCK)
      INNER JOIN olComun.dbo.CategoriasProductos cpr WITH(NOLOCK) ON cpr.cprId = p.cprId
      WHERE p.proCodigo IS NOT NULL AND LTRIM(RTRIM(p.proCodigo)) != ''
        AND p.proEliminado = 0
        AND (
             p.proCodigo LIKE 'PL%'   OR p.proCodigo LIKE 'SUBR%'
          OR p.proCodigo LIKE 'PR%'   OR p.proCodigo LIKE 'PRD%'
          OR p.proCodigo LIKE 'LLPL%' OR p.proCodigo LIKE 'HZPL%'
          OR p.proCodigo LIKE 'HZPR%' OR p.proCodigo LIKE 'EVPL%'
          OR p.proCodigo LIKE 'VOPL%' OR p.proCodigo LIKE 'PMPL%'
        )
      ORDER BY p.proCodigo
    `);
    const recetas = recResult.recordset;
    log(`  ${recetas.length} recetas encontradas en Brilo`);

    // ── 2. Botón de cocina y sucursales de menú ─────────────────────────────
    log('[2/4] Leyendo asignaciones cocina / menú...');
    const cocinaResult = await sqlPool.request().query(`
      SELECT
        TRIM(p.proCodigo) AS codigo,
        px.sucId,
        px.ccirstId
      FROM olRestaurante.dbo.ProductoXCocinaXSucRst px WITH(NOLOCK)
      JOIN olComun.dbo.Productos p WITH(NOLOCK) ON p.proId = px.proId
      WHERE px.pxcsrEliminado = 0
        AND p.proCodigo IS NOT NULL
    `);
    // Map: codigo → [{ sucId, ccirstId }]
    const cocinaMap = {};
    cocinaResult.recordset.forEach(r => {
      if (!cocinaMap[r.codigo]) cocinaMap[r.codigo] = [];
      cocinaMap[r.codigo].push({ sucId: r.sucId, ccirstId: r.ccirstId });
    });
    log(`  ${cocinaResult.recordset.length} asignaciones cocina/menú`);

    // ── 3. Ingredientes de todas las recetas ────────────────────────────────
    log('[3/4] Leyendo ingredientes...');
    const proIds = recetas.map(r => r.proId).join(',');
    const ingResult = await sqlPool.request().query(`
      SELECT
        mx.proId              AS rec_proId,
        TRIM(mat.proCodigo)   AS ing_codigo,
        mat.proNombre         AS ing_nombre,
        cpr.cprNombre         AS ing_cat_nombre,
        mx.mxprCantidad       AS cantidad_base,
        ISNULL(uniBase.uniNombre, 'u') AS unidad_base,
        mx.mxprCantUnidad     AS cantidad_pres,
        uniPres.uniNombre     AS unidad_pres
      FROM olComun.dbo.MaterialesXProducto mx WITH(NOLOCK)
      JOIN olComun.dbo.Productos mat WITH(NOLOCK) ON mat.proId = mx.proIdMaterial
      LEFT JOIN olComun.dbo.CategoriasProductos cpr WITH(NOLOCK) ON cpr.cprId = mat.cprId
      LEFT JOIN olComun.dbo.Unidades uniBase WITH(NOLOCK) ON uniBase.uniId = mat.uniId
      LEFT JOIN olComun.dbo.Unidades uniPres WITH(NOLOCK) ON uniPres.uniId = mx.uniId
      WHERE mx.proId IN (${proIds})
        AND mx.mxprActivo = 1 AND mx.mxprEliminado = 0
        AND mx.mxprCantidad > 0
        AND mat.proCodigo IS NOT NULL
      ORDER BY mx.proId, mx.mxprId
    `);
    // Map: proId → [ ingredientes ]
    const proIdToCodigo = {};
    recetas.forEach(r => { proIdToCodigo[r.proId] = r.codigo; });

    const ingMap = {}; // receta_codigo → [ ingredientes ]
    ingResult.recordset.forEach(r => {
      const cod = proIdToCodigo[r.rec_proId];
      if (!cod) return;
      if (!ingMap[cod]) ingMap[cod] = [];
      ingMap[cod].push(r);
    });
    log(`  ${ingResult.recordset.length} líneas de ingredientes`);

    // ── 4. Upsert en RDS ────────────────────────────────────────────────────
    log('[4/4] Guardando snapshot en RDS...');
    let recOk = 0, ingOk = 0;
    const BATCH = 50;

    for (let i = 0; i < recetas.length; i += BATCH) {
      const chunk = recetas.slice(i, i + BATCH);

      // Upsert recetas
      for (const r of chunk) {
        const sucursales = cocinaMap[r.codigo] ?? [];
        const enBoton    = sucursales.length > 0;
        const tipoRec    = esSubRec(r.codigo, r.cat_nombre) ? 'sub_receta' : 'plato';

        await pg.query(`
          INSERT INTO brilo_snapshot_recetas
            (codigo, nombre, tipo_receta, categoria_codigo, categoria_nombre,
             precio, activo, no_enviar_cocina, en_boton_cocina, pro_id, sucursales,
             synced_at, created_at, updated_at)
          VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,NOW(),NOW())
          ON CONFLICT (codigo) DO UPDATE SET
            nombre           = EXCLUDED.nombre,
            tipo_receta      = EXCLUDED.tipo_receta,
            categoria_codigo = EXCLUDED.categoria_codigo,
            categoria_nombre = EXCLUDED.categoria_nombre,
            precio           = EXCLUDED.precio,
            activo           = EXCLUDED.activo,
            no_enviar_cocina = EXCLUDED.no_enviar_cocina,
            en_boton_cocina  = EXCLUDED.en_boton_cocina,
            pro_id           = EXCLUDED.pro_id,
            sucursales       = EXCLUDED.sucursales,
            synced_at        = EXCLUDED.synced_at,
            updated_at       = EXCLUDED.updated_at
        `, [
          r.codigo, r.nombre?.trim(), tipoRec,
          r.cat_codigo, r.cat_nombre?.trim(),
          r.precio ?? null,
          r.activo ?? true,
          r.no_enviar_cocina ?? false,
          enBoton,
          r.proId,
          JSON.stringify(sucursales),
          NOW,
        ]);
        recOk++;

        // Upsert ingredientes de esta receta
        const ings = ingMap[r.codigo] ?? [];
        for (const ing of ings) {
          const esSub = esReceta(ing.ing_codigo);
          await pg.query(`
            INSERT INTO brilo_snapshot_ingredientes
              (receta_codigo, ingrediente_codigo, ingrediente_nombre, es_sub_receta,
               cantidad_base, unidad_base, cantidad_pres, unidad_pres, activo,
               synced_at, created_at, updated_at)
            VALUES ($1,$2,$3,$4,$5,$6,$7,$8,true,$9,NOW(),NOW())
            ON CONFLICT (receta_codigo, ingrediente_codigo) DO UPDATE SET
              ingrediente_nombre = EXCLUDED.ingrediente_nombre,
              es_sub_receta      = EXCLUDED.es_sub_receta,
              cantidad_base      = EXCLUDED.cantidad_base,
              unidad_base        = EXCLUDED.unidad_base,
              cantidad_pres      = EXCLUDED.cantidad_pres,
              unidad_pres        = EXCLUDED.unidad_pres,
              activo             = EXCLUDED.activo,
              synced_at          = EXCLUDED.synced_at,
              updated_at         = EXCLUDED.updated_at
          `, [
            r.codigo,
            ing.ing_codigo,
            ing.ing_nombre?.trim(),
            esSub,
            parseFloat(ing.cantidad_base) || null,
            ing.unidad_base?.trim() || null,
            parseFloat(ing.cantidad_pres) > 0 ? parseFloat(ing.cantidad_pres) : null,
            ing.unidad_pres?.trim() || null,
            NOW,
          ]);
          ingOk++;
        }
      }
      process.stdout.write(`\r  Procesadas: ${Math.min(i + BATCH, recetas.length)}/${recetas.length}  `);
    }
    console.log();

    // Marcar inactivos ingredientes que ya no están en Brilo
    await pg.query(`
      UPDATE brilo_snapshot_ingredientes
      SET activo = false, updated_at = NOW()
      WHERE synced_at < $1 AND activo = true
    `, [NOW]);

    log(`\n  Recetas snapshot:      ${recOk}`);
    log(`  Ingredientes snapshot: ${ingOk}`);
    log('════════════════════════════════════════════');
    log('SNAPSHOT COMPLETO');

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
