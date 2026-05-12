/**
 * check_huizucar_sub_recetas.js
 *
 * Verifica si existen sub-recetas en SQL Server (olcomun) para la sucursal Huizucar
 * y cuáles ya están (o no) en PostgreSQL.
 *
 * Uso: node check_huizucar_sub_recetas.js
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
  connectionTimeoutMillis: 30000,
};

async function main() {
  console.log('Conectando a SQL Server...');
  const sqlPool = await sql.connect(sqlConfig);
  console.log('SQL Server OK\n');

  console.log('Conectando a PostgreSQL...');
  const pool = new Pool(pgConfig);
  const pg   = await pool.connect();
  console.log('PostgreSQL OK\n');

  // ── 1. Todas las sub-recetas en SQL Server (categoria Platos Sub-Recetas) ──
  console.log('=== [1] Sub-recetas globales en SQL Server (Platos Sub-Recetas) ===');
  const globalRes = await sqlPool.request().query(`
    SELECT DISTINCT
      p.proId        AS id_origen,
      p.proCodigo    AS codigo,
      p.proNombre    AS nombre,
      cpr.cprNombre  AS categoria
    FROM olComun.dbo.Productos p WITH (NOLOCK)
    INNER JOIN olComun.dbo.CategoriasProductos cpr WITH (NOLOCK)
      ON p.cprId = cpr.cprId
      AND cpr.cprNombre = 'Platos Sub-Recetas'
    INNER JOIN olComun.dbo.MaterialesXProducto mx WITH (NOLOCK)
      ON mx.proId = p.proId AND mx.mxprEliminado = 0
    ORDER BY p.proCodigo
  `);
  const globalSubRec = globalRes.recordset;
  console.log(`Total sub-recetas en SQL Server: ${globalSubRec.length}`);
  globalSubRec.forEach(r => console.log(`  ${r.codigo} | ${r.nombre}`));

  // ── 2. Sub-recetas en SQL Server asignadas a menús de Huizucar ──────────
  // Intentamos buscar qué sucursal es Huizucar en SQL Server
  console.log('\n=== [2] Sucursales disponibles en SQL Server ===');
  let sucRes;
  try {
    sucRes = await sqlPool.request().query(`
      SELECT TOP 20 sucId, sucNombre, sucCodigo
      FROM olComun.dbo.Sucursales WITH (NOLOCK)
      WHERE sucEliminada = 0
      ORDER BY sucNombre
    `);
    sucRes.recordset.forEach(r => console.log(`  [${r.sucId}] ${r.sucNombre} (${r.sucCodigo})`));
  } catch (e) {
    console.log('  No se pudo consultar tabla Sucursales:', e.message);
  }

  // ── 3. Buscar tablas que relacionen productos con sucursales/menus ───────
  console.log('\n=== [3] Búsqueda de productos asignados a Huizucar ===');
  let huizucarSucId = null;
  if (sucRes) {
    const hRow = sucRes.recordset.find(r =>
      r.sucNombre?.toLowerCase().includes('huizucar') ||
      r.sucCodigo?.toLowerCase().includes('huiz')
    );
    if (hRow) {
      huizucarSucId = hRow.sucId;
      console.log(`Huizucar encontrado: ID=${hRow.sucId}, Nombre="${hRow.sucNombre}"`);
    } else {
      console.log('Huizucar NO encontrado en tabla Sucursales de SQL Server.');
      console.log('Mostrando todas las sucursales para inspección manual:');
      sucRes.recordset.forEach(r => console.log(`  [${r.sucId}] ${r.sucNombre}`));
    }
  }

  if (huizucarSucId) {
    // Intentar buscar menús/listas asignadas a esa sucursal
    try {
      const menuRes = await sqlPool.request()
        .input('sucId', sql.Int, huizucarSucId)
        .query(`
          SELECT DISTINCT
            p.proCodigo AS codigo,
            p.proNombre AS nombre,
            cpr.cprNombre AS categoria
          FROM olComun.dbo.Productos p WITH (NOLOCK)
          INNER JOIN olComun.dbo.CategoriasProductos cpr WITH (NOLOCK)
            ON p.cprId = cpr.cprId AND cpr.cprNombre = 'Platos Sub-Recetas'
          INNER JOIN olComun.dbo.MaterialesXProducto mx WITH (NOLOCK)
            ON mx.proId = p.proId AND mx.mxprEliminado = 0
          INNER JOIN olComun.dbo.SucursalesXProducto sxp WITH (NOLOCK)
            ON sxp.proId = p.proId AND sxp.sucId = @sucId
          ORDER BY p.proCodigo
        `);
      console.log(`\nSub-recetas "Platos Sub-Recetas" asignadas a Huizucar (via SucursalesXProducto):`);
      if (menuRes.recordset.length === 0) {
        console.log('  → Ninguna encontrada.');
      } else {
        menuRes.recordset.forEach(r => console.log(`  ${r.codigo} | ${r.nombre}`));
      }
    } catch (e) {
      console.log('  No existe SucursalesXProducto o error:', e.message);
    }
  }

  // ── 4. Estado en PostgreSQL: cuáles sub-recetas ya existen ───────────────
  console.log('\n=== [4] Estado en PostgreSQL (sub-recetas tipo_receta=sub_receta) ===');
  const pgRes = await pg.query(`
    SELECT r.id, r.codigo_origen, r.nombre, r.tipo, r.activa,
           COUNT(ri.id) AS num_ingredientes
    FROM recetas r
    LEFT JOIN receta_ingredientes ri ON ri.receta_id = r.id
    WHERE r.tipo_receta = 'sub_receta'
    GROUP BY r.id, r.codigo_origen, r.nombre, r.tipo, r.activa
    ORDER BY r.codigo_origen
    LIMIT 50
  `);
  console.log(`Total sub-recetas en PG (tipo_receta=sub_receta): ${pgRes.rows.length}`);
  pgRes.rows.forEach(r =>
    console.log(`  [${r.id}] ${r.codigo_origen} | ${r.nombre} | tipo="${r.tipo}" | activa=${r.activa} | ingredientes=${r.num_ingredientes}`)
  );

  // ── 5. Sub-recetas en PG que tienen tipo LIKE '%sub%receta%' ────────────
  console.log('\n=== [5] Sub-recetas en PG con tipo LIKE \'%sub%receta%\' ===');
  const pgSubRes = await pg.query(`
    SELECT r.id, r.codigo_origen, r.nombre, r.tipo, r.tipo_receta
    FROM recetas r
    WHERE lower(coalesce(r.tipo,'')) LIKE '%sub%receta%'
    ORDER BY r.codigo_origen
    LIMIT 50
  `);
  console.log(`Total: ${pgSubRes.rows.length}`);
  pgSubRes.rows.forEach(r =>
    console.log(`  [${r.id}] ${r.codigo_origen} | ${r.nombre} | tipo="${r.tipo}" | tipo_receta="${r.tipo_receta}"`)
  );

  // ── 6. Cross-check: cuáles SQL Server sub-recetas NO están en PG ─────────
  const pgCodigos = new Set(pgRes.rows.map(r => String(r.codigo_origen ?? '').trim()));
  const faltantes = globalSubRec.filter(r => !pgCodigos.has(String(r.codigo ?? '').trim()));
  console.log(`\n=== [6] Sub-recetas en SQL Server que NO están en PG ===`);
  if (faltantes.length === 0) {
    console.log('  → Todas las sub-recetas de SQL Server ya están en PG.');
  } else {
    console.log(`  Faltantes: ${faltantes.length}`);
    faltantes.forEach(r => console.log(`  ${r.codigo} | ${r.nombre}`));
  }

  // ── 7. receta_sucursal para Huizucar ─────────────────────────────────────
  console.log('\n=== [7] receta_sucursal para Huizucar (sucursal_id=9) ===');
  const rsRes = await pg.query(`
    SELECT r.codigo_origen, r.nombre, r.tipo_receta, rs.activa
    FROM receta_sucursal rs
    INNER JOIN recetas r ON r.id = rs.receta_id
    WHERE rs.sucursal_id = 9 AND r.tipo_receta = 'sub_receta'
    ORDER BY r.codigo_origen
    LIMIT 30
  `);
  console.log(`Sub-recetas con receta_sucursal para sucursal 9: ${rsRes.rows.length}`);
  rsRes.rows.forEach(r => console.log(`  ${r.codigo_origen} | ${r.nombre} | activa=${r.activa}`));

  pg.release();
  await pool.end();
  await sqlPool.close();
  console.log('\nDone.');
}

main().catch(err => {
  console.error('\nERROR:', err.message);
  process.exit(1);
});
