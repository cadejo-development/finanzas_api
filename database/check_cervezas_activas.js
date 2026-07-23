/**
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

 * check_cervezas_activas.js
 * Verifica en SQL Server y en PostgreSQL el estado de productos de cerveza
 * que ya no existen físicamente (Polka, Sammy, Lupe Reyes, etc.)
 *
 * Uso: node check_cervezas_activas.js
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const sqlConfig = {
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_USERNAME_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

const pgConfig = {
  host: process.env.DB_HOST,
  port: 5432,
  database: 'compras_db',
  user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
  connectionTimeoutMillis: 30000,
};

// Términos a buscar en SQL Server
const TERMINOS = ['POLKA', 'SAMMY', 'LUPE REYES'];

async function main() {
  console.log('=== CHECK CERVEZAS EN SQL SERVER ===\n');

  // ── SQL Server ──────────────────────────────────────────────────────────────
  const sqlPool = await sql.connect(sqlConfig);

  const termConditions = TERMINOS.map(t => `PRO.proNombre LIKE '%${t}%'`).join(' OR ');

  const qMSSQL = `
    SELECT
      PRO.proId       AS id,
      PRO.proCodigo   AS codigo,
      PRO.proNombre   AS nombre,
      PRO.proActivo   AS activo,
      CPR.cprNombre   AS categoria,
      PRO.proPrecio   AS precio,
      PRO.proCosto    AS costo
    FROM olComun.dbo.Productos PRO WITH (NOLOCK)
    LEFT JOIN olComun.dbo.CategoriasProductos CPR WITH (NOLOCK) ON PRO.cprId = CPR.cprId
    WHERE (${termConditions})
    ORDER BY CPR.cprNombre, PRO.proNombre
  `;

  const mssqlRows = (await sqlPool.request().query(qMSSQL)).recordset;

  console.log(`SQL Server — encontrados ${mssqlRows.length} producto(s) que coinciden:\n`);
  console.log('CÓDIGO         | NOMBRE                                  | ACTIVO | CATEGORÍA');
  console.log('───────────────|─────────────────────────────────────────|────────|───────────────────');
  for (const r of mssqlRows) {
    const activo  = r.activo ? '  SI  ' : '  NO  ';
    const codigo  = String(r.codigo  ?? '').padEnd(14);
    const nombre  = String(r.nombre  ?? '').slice(0, 40).padEnd(40);
    const cat     = String(r.categoria ?? '').slice(0, 20);
    console.log(`${codigo} | ${nombre} | ${activo} | ${cat}`);
  }

  // ── PostgreSQL (nuestro RDS) ─────────────────────────────────────────────────
  console.log('\n=== CHECK EN POSTGRESQL (RDS) ===\n');

  const pool = new Pool(pgConfig);
  const pg   = await pool.connect();

  // Ver tablas disponibles primero
  const tablas = (await pg.query(`
    SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename
  `)).rows.map(r => r.tablename);
  console.log('Tablas en compras_db:', tablas.join(', '), '\n');

  const termsPg = TERMINOS.map(t => `r.nombre ILIKE '%${t}%'`).join(' OR ');

  const qPg = `
    SELECT
      r.id,
      r.codigo_origen,
      r.nombre,
      r.activa,
      r.tipo,
      r.precio,
      rs.sucursal_id,
      rs.activa AS activa_en_sucursal
    FROM recetas r
    LEFT JOIN receta_sucursal rs ON rs.receta_id = r.id
    WHERE (${termsPg})
    ORDER BY r.nombre, rs.sucursal_id
  `;

  const pgRows = (await pg.query(qPg)).rows;

  if (pgRows.length === 0) {
    console.log('No se encontraron registros en PostgreSQL con esos términos.\n');
  } else {
    console.log(`PostgreSQL — ${pgRows.length} fila(s):\n`);
    console.log('CÓDIGO         | NOMBRE                                  | ACTIVO | SUCURSAL               | ACT_SUC | PRECIO');
    console.log('───────────────|─────────────────────────────────────────|────────|────────────────────────|─────────|───────');
    for (const r of pgRows) {
      const codigo  = String(r.codigo_origen ?? '').padEnd(14);
      const nombre  = String(r.nombre ?? '').slice(0, 40).padEnd(40);
      const activo  = r.activa    ? '  SI  ' : '  NO  ';
      const suc     = String(r.sucursal_id ?? 'N/A').padEnd(22);
      const actSuc  = r.activa_en_sucursal ? '  SI   ' : '  NO   ';
      const precio  = r.precio != null ? `$${Number(r.precio).toFixed(2)}` : 'N/A';
      console.log(`${codigo} | ${nombre} | ${activo} | ${suc} | ${actSuc} | ${precio}`);
    }
  }

  // Resumen: activos en alguna sucursal
  const activosEnSucursal = pgRows.filter(r => r.activa_en_sucursal);
  if (activosEnSucursal.length > 0) {
    console.log(`\n⚠️  ATENCION: ${activosEnSucursal.length} fila(s) aparecen activas en alguna sucursal:`);
    for (const r of activosEnSucursal) {
      console.log(`   - ${r.nombre} → sucursal_id=${r.sucursal_id ?? 'N/A'} ($${Number(r.precio ?? 0).toFixed(2)})`);
    }
  } else {
    console.log('\n✓ Ninguno aparece activo en sucursales en PostgreSQL.');
  }

  await pg.release();
  await pool.end();
  await sqlPool.close();
}

main().catch(e => { console.error(e); process.exit(1); });
