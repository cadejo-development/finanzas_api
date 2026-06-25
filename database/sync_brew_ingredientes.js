/**
 * sync_brew_ingredientes.js
 * Sincroniza maltas, lúpulos y cervezas desde Brilo (SQL Server) → compras_db (PostgreSQL)
 * Equivalente a BrewIngredientesSeeder pero usando Node.js (no requiere pdo_sqlsrv en PHP).
 *
 * Uso:
 *   node database/sync_brew_ingredientes.js
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const BRILO_CFG = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 30000 },
  requestTimeout: 180000,
};

const PG_CFG = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432,
  database: 'compras_db',
  user: 'cadejo_admin',
  password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
  keepAlive: true,
  idleTimeoutMillis: 600000,
};

// Exclusiones comunes — productos que no son ingredientes de cerveza
const EXCLUSIONES = `
  AND  LOWER(proNombre) NOT LIKE '%camisa%'
  AND  LOWER(proNombre) NOT LIKE '%camiseta%'
  AND  LOWER(proNombre) NOT LIKE '%playera%'
  AND  LOWER(proNombre) NOT LIKE '%polo%'
  AND  LOWER(proNombre) NOT LIKE '%gorra%'
  AND  LOWER(proNombre) NOT LIKE '%vaso%'
  AND  LOWER(proNombre) NOT LIKE '%bot. %'
  AND  LOWER(proNombre) NOT LIKE '%botella%'
  AND  LOWER(proNombre) NOT LIKE '%caja%'
  AND  LOWER(proNombre) NOT LIKE '%skyhopper%'
  AND  LOWER(proNombre) NOT LIKE '%souvenir%'
  AND  LOWER(proNombre) NOT LIKE '%merch%'
`;

const TIPOS = {
  malta: `
    LOWER(proNombre) LIKE '%malta%'
 OR LOWER(proNombre) LIKE '%malt%'
 OR LOWER(proNombre) LIKE '%grain%'
 OR LOWER(proNombre) LIKE '%grano%'
 OR LOWER(proNombre) LIKE '%trigo%'
 OR LOWER(proNombre) LIKE '%cebada%'
 OR LOWER(proNombre) LIKE '%avena%'
 OR LOWER(proNombre) LIKE '%wheat%'
 OR LOWER(proNombre) LIKE '%barley%'
 OR LOWER(proNombre) LIKE '%centeno%'
 OR LOWER(proNombre) LIKE '%pilsen%'
 OR LOWER(proNombre) LIKE '%caramel%'
 OR LOWER(proNombre) LIKE '%crystal%'
 OR LOWER(proNombre) LIKE '%black%'
 OR LOWER(proNombre) LIKE '%roast%'
 OR LOWER(proNombre) LIKE '%chocolate%'
 OR LOWER(proNombre) LIKE '%munich%'
 OR LOWER(proNombre) LIKE '%vienna%'
 OR LOWER(proNombre) LIKE '%pale%'`,

  lupulo: `
    LOWER(proNombre) LIKE '%lupulo%'
 OR LOWER(proNombre) LIKE '%lúpulo%'
 OR LOWER(proNombre) LIKE '%hop%'
 OR LOWER(proNombre) LIKE '%cascade%'
 OR LOWER(proNombre) LIKE '%centennial%'
 OR LOWER(proNombre) LIKE '%chinook%'
 OR LOWER(proNombre) LIKE '%citra%'
 OR LOWER(proNombre) LIKE '%simcoe%'
 OR LOWER(proNombre) LIKE '%galaxy%'
 OR LOWER(proNombre) LIKE '%mosaic%'
 OR LOWER(proNombre) LIKE '%saaz%'
 OR LOWER(proNombre) LIKE '%hallertau%'
 OR LOWER(proNombre) LIKE '%fuggle%'
 OR LOWER(proNombre) LIKE '%amarillo%'
 OR LOWER(proNombre) LIKE '%equinox%'
 OR LOWER(proNombre) LIKE '%el dorado%'
 OR LOWER(proNombre) LIKE '%magnum%'`,

  cerveza: `
    LOWER(proNombre) LIKE '%cadejo%'
 OR LOWER(proNombre) LIKE '%cerveza%'
 OR LOWER(proNombre) LIKE '%lager%'
 OR LOWER(proNombre) LIKE '%ale%'
 OR LOWER(proNombre) LIKE '%stout%'
 OR LOWER(proNombre) LIKE '%porter%'
 OR LOWER(proNombre) LIKE '%ipa%'
 OR LOWER(proNombre) LIKE '%pilsner%'
 OR LOWER(proNombre) LIKE '%tripel%'
 OR LOWER(proNombre) LIKE '%dubbel%'
 OR LOWER(proNombre) LIKE '%saison%'
 OR LOWER(proNombre) LIKE '%sour%'`,

  levadura: `
    LOWER(proNombre) LIKE '%levadura%'
 OR LOWER(proNombre) LIKE '%yeast%'
 OR LOWER(proNombre) LIKE '%safale%'
 OR LOWER(proNombre) LIKE '%saflager%'
 OR LOWER(proNombre) LIKE '%safbrew%'
 OR LOWER(proNombre) LIKE '%lallemand%'
 OR LOWER(proNombre) LIKE '%white labs%'
 OR LOWER(proNombre) LIKE '%wyeast%'`,
};

async function syncTipo(sqlConn, pg, tipo, where) {
  const query = `
    SELECT LTRIM(RTRIM(proCodigo)) AS codigo,
           LTRIM(RTRIM(proNombre))  AS nombre
    FROM   olComun.dbo.Productos WITH (NOLOCK)
    WHERE  proActivo = 1
      AND  (${where})
      ${EXCLUSIONES}
    ORDER BY proNombre
  `;

  const result = await sqlConn.request().query(query);
  const rows = result.recordset;
  console.log(`  [${tipo}] ${rows.length} registros desde Brilo`);

  if (!rows.length) {
    console.log(`  [${tipo}] Ninguno, se omite.`);
    return 0;
  }

  await pg.query('DELETE FROM brew_ingredientes WHERE tipo = $1', [tipo]);

  const now = new Date().toISOString();
  let inserted = 0;
  const CHUNK = 200;
  for (let i = 0; i < rows.length; i += CHUNK) {
    const chunk = rows.slice(i, i + CHUNK);
    const placeholders = chunk.map((_, j) => {
      const base = j * 5;
      return `($${base+1}, $${base+2}, $${base+3}, $${base+4}, $${base+5})`;
    }).join(', ');
    const values = chunk.flatMap(r => [tipo, r.codigo || '', r.nombre || '', now, now]);
    await pg.query(
      `INSERT INTO brew_ingredientes (tipo, codigo, nombre, created_at, updated_at) VALUES ${placeholders}`,
      values
    );
    inserted += chunk.length;
  }

  console.log(`  [${tipo}] ✓ ${inserted} registros insertados.`);
  return inserted;
}

async function main() {
  console.log('======================================================');
  console.log('SYNC BREW INGREDIENTES: Brilo SQL Server → compras_db');
  console.log('======================================================\n');

  console.log('Conectando a SQL Server...');
  const sqlConn = await sql.connect(BRILO_CFG);
  console.log('SQL Server OK.\n');

  console.log('Conectando a PostgreSQL (compras_db)...');
  const pg = new Pool(PG_CFG);
  await pg.query('SELECT 1');
  console.log('PostgreSQL OK.\n');

  const totales = {};
  for (const [tipo, where] of Object.entries(TIPOS)) {
    totales[tipo] = await syncTipo(sqlConn, pg, tipo, where);
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
