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
    SELECT LTRIM(RTRIM(p.proCodigo)) AS codigo,
           LTRIM(RTRIM(p.proNombre)) AS nombre,
           LTRIM(RTRIM(ISNULL(cp.cprNombre, ''))) AS estilo
    FROM   Productos p WITH (NOLOCK)
    INNER JOIN CategoriasProductos cp WITH (NOLOCK) ON cp.cprId = p.cprId
    WHERE  p.proActivo = 1
      AND  cp.cprNombre LIKE 'Cerveza%'
    ORDER BY p.proNombre
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

    // Para cervezas: strip presentaciones (12 OZ, 16 OZ, etc.) y deduplicar
    let finalRows = rows;
    if (tipo === 'cerveza') {
      const stripPresentation = (name) =>
        name
          .replace(/\s+\d+(\,\d+)?\s*(OZ|ONZ|ML|CL|CC|LT?|LITROS?|ONZAS?)\b.*/i, '')
          .replace(/\s+(LATA|BOTELLA|BARRIL|GROWLER|DRAFT|KEGG?|EXPORT[A-Z]*)\b.*/i, '')
          .replace(/\s+\d+\s*PACK\b.*/i, '')
          .trim();
      const seen = new Set();
      finalRows = [];
      for (const row of rows) {
        const cleanName = stripPresentation(row.nombre);
        if (!seen.has(cleanName)) {
          seen.add(cleanName);
          finalRows.push({ ...row, nombre: cleanName });
        }
      }
      console.log(`  → ${rows.length} registros Brilo → ${finalRows.length} cervezas únicas (sin presentaciones)`);
    }

    // Limpiar los existentes del mismo tipo
    await pg.query('DELETE FROM brew_ingredientes WHERE tipo = $1', [tipo]);

    // Insertar en chunks de 200
    const now = new Date().toISOString();
    let inserted = 0;
    const CHUNK = 200;
    for (let i = 0; i < finalRows.length; i += CHUNK) {
      const chunk = finalRows.slice(i, i + CHUNK);
      const values = [];
      const placeholders = chunk.map(r => {
        const o = values.length;
        values.push(tipo, r.codigo || '', r.nombre || '', r.estilo || null, true, now);
        return `($${o+1}, $${o+2}, $${o+3}, $${o+4}, $${o+5}, $${o+6})`;
      });
      await pg.query(
        `INSERT INTO brew_ingredientes (tipo, codigo, nombre, estilo, activo, created_at) VALUES ${placeholders.join(',')}`,
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
