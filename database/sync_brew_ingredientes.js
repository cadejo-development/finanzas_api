/**
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

 * sync_brew_ingredientes.js
 * Sincroniza ingredientes de cerveza desde Brilo (SQL Server) → compras_db (PostgreSQL)
 * Usa códigos de CATEGORÍA de Brilo para precisión total — no LIKE por nombre.
 *
 * Categorías Brilo:
 *   MP-02  Cereales        → malta
 *   MP-05  Lupulo          → lupulo
 *   GT-02  CIF + MP-04     → levadura (filtrado por nombre para excluir CO2, pegamento)
 *   MP-01  Aditivos        → mineral (CaCl, CaSO4, H3PO4)
 *   PT-01/02/03 Cerveza    → cerveza
 *
 * Uso: node database/sync_brew_ingredientes.js
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const BRILO_CFG = {
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_USERNAME_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 30000 },
  requestTimeout: 180000,
};

const PG_CFG = {
  host: process.env.DB_HOST,
  port: 5432, database: 'compras_db', user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false }, keepAlive: true, idleTimeoutMillis: 600000,
};

// Cada tipo define su WHERE sobre olComun.dbo.Productos + CategoriasProductos
const TIPOS = {
  malta: {
    join: true,
    where: `cp.cprCodigo = 'MP-02'`,
  },
  lupulo: {
    join: true,
    where: `cp.cprCodigo = 'MP-05'`,
  },
  levadura: {
    join: true,
    // GT-02 tiene levaduras reales + CO2 + pegamento, filtramos por nombre
    where: `cp.cprCodigo IN ('GT-02', 'MP-04')
      AND (
           LOWER(p.proNombre) LIKE '%levadura%'
        OR LOWER(p.proNombre) LIKE '%yeast%'
        OR LOWER(p.proNombre) LIKE '%safale%'
        OR LOWER(p.proNombre) LIKE '%saflager%'
        OR LOWER(p.proNombre) LIKE '%safbrew%'
        OR LOWER(p.proNombre) LIKE '%lallemand%'
        OR LOWER(p.proNombre) LIKE '%fermentis%'
        OR LOWER(p.proNombre) LIKE '%wyeast%'
        OR LOWER(p.proNombre) LIKE '%white labs%'
      )`,
  },
  mineral: {
    join: true,
    // MP-01 Aditivos: CaCl, CaSO4, H3PO4 — minerales de agua de macerado
    where: `cp.cprCodigo = 'MP-01'`,
  },
  cerveza: {
    join: true,
    where: `cp.cprCodigo IN ('PT-01', 'PT-02', 'PT-03')`,
  },
};

async function syncTipo(sqlConn, pg, tipo, cfg) {
  const query = `
    SELECT LTRIM(RTRIM(p.proCodigo)) AS codigo,
           LTRIM(RTRIM(p.proNombre)) AS nombre
    FROM   olComun.dbo.Productos p WITH (NOLOCK)
    LEFT JOIN olComun.dbo.CategoriasProductos cp WITH (NOLOCK) ON cp.cprId = p.cprId
    WHERE  p.proActivo = 1
      AND  p.proEliminado = 0
      AND  (${cfg.where})
    ORDER BY p.proNombre
  `;

  const result = await sqlConn.request().query(query);
  const rows = result.recordset;
  console.log(`  [${tipo}] ${rows.length} registros desde Brilo`);

  if (!rows.length) {
    console.log(`  [${tipo}] Ninguno — se omite.`);
    return 0;
  }

  await pg.query('DELETE FROM brew_ingredientes WHERE tipo = $1', [tipo]);

  const now = new Date().toISOString();
  const CHUNK = 200;
  let inserted = 0;
  for (let i = 0; i < rows.length; i += CHUNK) {
    const chunk = rows.slice(i, i + CHUNK);
    const placeholders = chunk.map((_, j) => {
      const b = j * 5;
      return `($${b+1},$${b+2},$${b+3},$${b+4},$${b+5})`;
    }).join(',');
    const values = chunk.flatMap(r => [tipo, r.codigo || '', r.nombre || '', now, now]);
    await pg.query(
      `INSERT INTO brew_ingredientes (tipo, codigo, nombre, created_at, updated_at) VALUES ${placeholders}`,
      values
    );
    inserted += chunk.length;
  }

  console.log(`  [${tipo}] ✓ ${inserted} insertados.`);
  return inserted;
}

async function main() {
  console.log('======================================================');
  console.log('SYNC BREW INGREDIENTES: Brilo SQL Server → compras_db');
  console.log('Usa categorías Brilo (MP-02, MP-05, GT-02, MP-01...)');
  console.log('======================================================\n');

  console.log('Conectando a SQL Server...');
  const sqlConn = await sql.connect(BRILO_CFG);
  console.log('SQL Server OK.\n');

  console.log('Conectando a PostgreSQL (compras_db)...');
  const pg = new Pool(PG_CFG);
  await pg.query('SELECT 1');
  console.log('PostgreSQL OK.\n');

  const totales = {};
  for (const [tipo, cfg] of Object.entries(TIPOS)) {
    totales[tipo] = await syncTipo(sqlConn, pg, tipo, cfg);
  }

  await sqlConn.close();
  await pg.end();

  console.log('\n======================================================');
  console.log('RESUMEN:');
  for (const [tipo, n] of Object.entries(totales)) {
    console.log(`  ${tipo.padEnd(10)} ${n} ingredientes`);
  }
  console.log('======================================================');
}

main().catch(err => {
  console.error('ERROR:', err.message);
  process.exit(1);
});
