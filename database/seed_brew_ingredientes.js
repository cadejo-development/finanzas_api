/**
 * seed_brew_ingredientes.js
 *
 * Sincroniza maltas, lúpulos y cervezas desde Brilo (SQL Server / VPN)
 * hacia la tabla brew_ingredientes en el RDS PostgreSQL (compras_db).
 *
 * EJECUTAR SOLO CON VPN ACTIVA:
 *   node database/seed_brew_ingredientes.js
 *
 * Para re-sincronizar (limpia y recarga):
 *   node database/seed_brew_ingredientes.js
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const MSSQL_CFG = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

const PG_CFG = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'compras_db', user: 'cadejo_admin',
  password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
};

const QUERIES = {
  malta: `
    SELECT LTRIM(RTRIM(proCodigo)) AS codigo,
           LTRIM(RTRIM(proNombre)) AS nombre
    FROM   olComun.dbo.Productos WITH (NOLOCK)
    WHERE  proActivo = 1
      AND (
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
         OR LOWER(proNombre) LIKE '%pale%'
      )
    ORDER BY proNombre
  `,
  lupulo: `
    SELECT LTRIM(RTRIM(proCodigo)) AS codigo,
           LTRIM(RTRIM(proNombre)) AS nombre
    FROM   olComun.dbo.Productos WITH (NOLOCK)
    WHERE  proActivo = 1
      AND (
            LOWER(proNombre) LIKE '%lupulo%'
         OR LOWER(proNombre) LIKE '%l%pulo%'
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
         OR LOWER(proNombre) LIKE '%magnum%'
      )
    ORDER BY proNombre
  `,
  cerveza: `
    SELECT LTRIM(RTRIM(proCodigo)) AS codigo,
           LTRIM(RTRIM(proNombre)) AS nombre
    FROM   olComun.dbo.Productos WITH (NOLOCK)
    WHERE  proActivo = 1
      AND (
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
         OR LOWER(proNombre) LIKE '%sour%'
      )
    ORDER BY proNombre
  `,
};

async function main() {
  console.log('Conectando a Brilo SQL Server...');
  const msPool = await sql.connect(MSSQL_CFG);

  console.log('Conectando a PostgreSQL RDS...');
  const pg = new Pool(PG_CFG);

  for (const [tipo, query] of Object.entries(QUERIES)) {
    console.log(`\nJalando ${tipo}s desde Brilo...`);
    const result = await msPool.request().query(query);
    const rows = result.recordset;

    if (!rows.length) {
      console.log(`  ⚠ No se encontraron registros para tipo=${tipo}`);
      continue;
    }

    // Limpiar los existentes del mismo tipo
    await pg.query('DELETE FROM brew_ingredientes WHERE tipo = $1', [tipo]);

    // Insertar en chunks de 200
    const now = new Date().toISOString();
    let inserted = 0;
    const CHUNK = 200;
    for (let i = 0; i < rows.length; i += CHUNK) {
      const chunk = rows.slice(i, i + CHUNK);
      const values = [];
      const placeholders = chunk.map(r => {
        const o = values.length;
        values.push(tipo, r.codigo || '', r.nombre || '', true, now);
        return `($${o+1}, $${o+2}, $${o+3}, $${o+4}, $${o+5})`;
      });
      await pg.query(
        `INSERT INTO brew_ingredientes (tipo, codigo, nombre, activo, created_at) VALUES ${placeholders.join(',')}`,
        values
      );
      inserted += chunk.length;
    }

    console.log(`  ✓ ${tipo}: ${inserted} registros insertados.`);
  }

  await msPool.close();
  await pg.end();
  console.log('\nSincronización completa.');
}

main().catch(e => {
  console.error('ERROR:', e.message);
  process.exit(1);
});
