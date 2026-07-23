/**
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

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
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_USERNAME_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

const PG_CFG = {
  host: process.env.DB_HOST, port: 5432,
  database: 'compras_db', user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
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
  levadura: `
    SELECT LTRIM(RTRIM(proCodigo)) AS codigo,
           LTRIM(RTRIM(proNombre)) AS nombre
    FROM   olComun.dbo.Productos WITH (NOLOCK)
    WHERE  proActivo = 1
      AND  (
              LOWER(proNombre) LIKE '%levadura%'
           OR LOWER(proNombre) LIKE '%yeast%'
           OR LOWER(proNombre) LIKE '%safale%'
           OR LOWER(proNombre) LIKE '%saflager%'
           OR LOWER(proNombre) LIKE '%safbrew%'
           OR LOWER(proNombre) LIKE '%lallemand%'
           OR LOWER(proNombre) LIKE '%wyeast%'
           OR LOWER(proNombre) LIKE '%white labs%'
           OR LOWER(proNombre) LIKE '%fermentis%'
           OR LOWER(proNombre) LIKE '%windsor%'
      )
      AND  LOWER(proNombre) NOT LIKE '%nevada%'   -- levadura de pan, no cerveza
      AND  LOWER(proNombre) NOT LIKE '%booster%'  -- aditivo, no levadura
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

    // Para cervezas: strip presentaciones y excluir promos/combos/servicios
    let finalRows = rows;
    if (tipo === 'cerveza') {
      // 1. Limpiar sufijos de envase/canal/formato del nombre
      const clean = (name) => name
        .toUpperCase()
        .replace(/\s+/g, ' ')
        // corregir typos conocidos de Brilo
        .replace(/^CERVEZACALABAZA\b/, 'CERVEZA CALABAZA')
        .replace(/^CERVEZA\s+LABURRO\b/, 'CERVEZA LA BURRO')
        // canales / promo-labels
        .replace(/\s*-\s*(VENECIA|VN|VO|OP|AE)\b.*/i, '')
        .replace(/\s*-\s*(LA\s+HORA|AO)\b.*/i, '')
        .replace(/\s+\d+X\d+\b.*/i, '')           // 3X2, 2X1, etc.
        // tipo de servicio / contenedor
        .replace(/\s+DRAFT.*/i, '')
        .replace(/\s+(BARRIL|GROWLER|KEGG?).*/i, '')
        .replace(/\s+LITRO.*/i, '')
        .replace(/\s+REFILL?.*/i, '')
        // formatos de envase
        .replace(/\s+BOT\.?(\s+\d+\s*(ML|OZ|ONZ))?.*/i, '')
        .replace(/\s+BOX(\s+\d+)?.*/i, '')
        .replace(/\s+CAJA\s+\d+.*/i, '')
        .replace(/\s+SIX[\s-]?PACK.*/i, '')
        .replace(/\s+(FOUR|FOR)[\s-]?PACK.*/i, '')
        .replace(/\s+\d+[\s-]?PACK.*/i, '')
        .replace(/\s+SIX\s+\d+\s*U\..*/i, '')
        // volúmenes — sin requerir espacio antes (NACIONAL12 ONZ, PEACH CHULA14 OZ)
        .replace(/\d+([\.,]\d+)?\s*(OZ|ONZ|ML|CL|CC|LT?|LITROS?|ONZAS?)\b.*/i, '')
        .replace(/\s+\d+\s*U\..*/i, '')
        // tags al final — VR=retornable, VN=venta nacional, etc.
        .replace(/\s+(VR|VN|OP|AE)\s*$/i, '')
        .replace(/\s+BONIFICACI[OÓÒ]N.*/i, '')
        .replace(/\s+PRECIO.*/i, '')
        .replace(/\s+DE\s*$/, '')
        .replace(/[.\s-]+$/, '')
        .trim();

      // 2. Patrones que indican que NO es una receta base de cerveza
      const EXCLUDE = [
        /^\d/,                                    // empieza con número: 2 SIX PACKS, 2DA CERV
        /^AGRANDADO\b/,
        /^BALDE\b/,
        /^BARRA\s/,
        /^BARRIL\s/,
        /^BEER\s+OF\b/,
        /^BIRRIA\b/,
        /^CAJA\b/,
        /^CATA\b/,
        /^CERV(EZA)?\s+(DE\s+)?LINEA\b/,
        /^CERVEZA\s+DE\b/,
        /^CERVEZA\s+EXTERNA\b/,
        /^CERVEZA\s+PREMIO\b/,
        /^CERVEZA\s*$/,
        /^CERVEZA\s+1\s/,
        /^CERVEZA\s+DE\s*$/,
        /^DIA\s+DE\b/,
        /^DRAFT\b/,
        /^EXTRA\s+POR\b/,
        /^(FOR|FOUR)\s+PACK\b/,
        /^GROWLER\b/,
        /^LITRO\b/,
        /^MINUTA\b/,
        /^NACIONAL\s+O\b/,
        /^PAQ\s+\d/,
        /^PROMO/,
        /^PROM\b/,
        /^REFIL/,
        /^REST(AURANTE)?\.?\b/,            // REST. REFILL / RESTAURANTE REFILL
        /^SAMPLER\b/,
        /^SIX\b/,
        /^SIXPACK\b/,
        /^RUBIA\b/,                               // solo presentaciones, no receta base
      ];

      const isExcluded = (n) => EXCLUDE.some(r => r.test(n));

      // 3. Normalizar clave para deduplicar (quitar prefijos CERVEZA LA / CERVEZA / LA)
      const keyOf = (n) => n
        .replace(/^CERVEZA\s+LA\s+/, '')
        .replace(/^CERVEZA\s+/, '')
        .replace(/^LA\s+/, '')
        .trim();

      const seen = new Set();
      finalRows = [];
      for (const row of rows) {
        const cleaned = clean(row.nombre);
        if (isExcluded(cleaned)) continue;
        const key = keyOf(cleaned);
        if (key.length < 3) continue;
        if (!seen.has(key)) {
          seen.add(key);
          finalRows.push({ ...row, nombre: cleaned });
        }
      }
      console.log(`  → ${rows.length} registros Brilo → ${finalRows.length} cervezas únicas (sin promos/presentaciones)`);
    }

    // Para levaduras: strip gramaje (500 gr, 11.5 gr, sachet) y deduplicar
    if (tipo === 'levadura') {
      const cleanLev = (name) => name
        .toUpperCase()
        .replace(/\s+/g, ' ')
        .replace(/\s+PRUEBA\b.*/i, '')                          // "prueba Fermentis 500gr" → quitar todo
        .replace(/\s+\d+([.,]\d+)?\s*(GR|G|KG|ML|SACHET)\b.*/i, '')
        .replace(/\s+SACHET\b.*/i, '')
        .replace(/[.\s-]+$/, '')
        .trim();
      const seen2 = new Set();
      finalRows = [];
      for (const row of rows) {
        const cleaned = cleanLev(row.nombre);
        if (cleaned.length < 3) continue;
        if (!seen2.has(cleaned)) {
          seen2.add(cleaned);
          finalRows.push({ ...row, nombre: cleaned });
        }
      }
      console.log(`  → ${rows.length} registros Brilo → ${finalRows.length} levaduras únicas`);
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
