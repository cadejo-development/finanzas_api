/**
 * Compara nombres de categorías entre Brilo (SQL Server) y receta_categorias (PG).
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
  ssl: { rejectUnauthorized: false },
};

async function main() {
  const sqlPool = await sql.connect(sqlCfg);
  const pg = new Pool(pgCfg);

  // Categorías en Brilo (todas, sin filtro)
  const briloRows = (await sqlPool.request().query(
    `SELECT cprCodigo AS clave, cprNombre AS nombre
     FROM olComun.dbo.CategoriasProductos
     WHERE cprNombre LIKE 'Platos%' OR cprNombre LIKE 'Bebidas%' OR cprNombre LIKE 'Bebidas%'
     ORDER BY cprCodigo`
  )).recordset;

  // Categorías en PG
  const pgRows = (await pg.query(
    `SELECT id, key, nombre, activa FROM receta_categorias ORDER BY orden, nombre`
  )).rows;

  const briloNombres = new Set(briloRows.map(r => r.nombre.trim().toLowerCase()));
  const pgNombres    = new Set(pgRows.map(r => r.nombre.trim().toLowerCase()));

  console.log('\n══ BRILO (CategoriasProductos) ══════════════════');
  briloRows.forEach(r => {
    const enPG = pgNombres.has(r.nombre.trim().toLowerCase());
    console.log(`  [${r.clave}] ${r.nombre}${enPG ? '' : '  ← FALTA EN PG'}`);
  });

  console.log('\n══ PG (receta_categorias) ═══════════════════════');
  pgRows.forEach(r => {
    const enBrilo = briloNombres.has(r.nombre.trim().toLowerCase());
    console.log(`  [${r.key ?? '??'}] ${r.nombre}  activa=${r.activa}${enBrilo ? '' : '  ← NO ESTÁ EN BRILO'}`);
  });

  await sqlPool.close();
  await pg.end();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
