require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

/**
 * Pobla unidad_compra / unidad_compra_nombre / factor_unidad_compra en productos
 * leyendo la unidad DEFAULT de compra desde Brilo (vwUnidadesXProductoYUniBase).
 *
 * Regla: la unidad con unipDefault=true y uniActiva=1 es la unidad en que
 * típicamente se compra/ordena el producto.
 * Cuando esa unidad == unidad base (uniBase=true), significa que se compra
 * en la misma unidad del stock (no hay presentación diferente).
 */

const sql    = require('mssql');
const { Client } = require('pg');

const MSSQL_CFG = {
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_PASSWORD_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: 2033, database: 'olInventario',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

const PG_CFG = {
  host: process.env.DB_HOST,
  port: 5432, database: 'compras_db',
  user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
};

async function main() {
  const pool = await sql.connect(MSSQL_CFG);
  const pg   = new Client(PG_CFG);
  await pg.connect();
  console.log('Conectado.\n');

  // 1. Traer todos nuestros productos con su codigo
  const { rows: pgProds } = await pg.query(
    `SELECT id, codigo FROM productos WHERE activo = true AND codigo IS NOT NULL`
  );
  const pgMap = {};
  pgProds.forEach(p => { pgMap[p.codigo.trim()] = p.id; });
  console.log(`Productos en catálogo: ${pgProds.length}`);

  // 2. Traer unidades default de compra de Brilo (unipDefault=true)
  const briloRes = await pool.request().query(`
    SELECT
      p.proCodigo                   AS codigo,
      v.uniAbreviacion              AS unidad_compra,
      v.uniNombre                   AS unidad_compra_nombre,
      v.unipCantidad                AS factor,
      v.uniBase                     AS es_base
    FROM olComun.dbo.Productos p WITH(NOLOCK)
    JOIN olComun.dbo.vwUnidadesXProductoYUniBase v WITH(NOLOCK)
      ON v.proId = p.proId
     AND v.unipDefault = 1
     AND v.uniActiva   = 1
    WHERE p.proActivo = 1
  `);

  const briloMap = {};
  briloRes.recordset.forEach(r => {
    briloMap[r.codigo?.trim()] = r;
  });
  console.log(`Productos Brilo con unidad default: ${briloRes.recordset.length}`);

  // 3. Actualizar PostgreSQL
  let actualizados = 0;
  let sinMatch     = 0;
  let mismaUnidad  = 0;

  await pg.query('BEGIN');
  try {
    for (const [codigo, prodId] of Object.entries(pgMap)) {
      const brilo = briloMap[codigo];
      if (!brilo) { sinMatch++; continue; }

      // Si es la unidad base, no hay presentación de compra distinta
      if (brilo.es_base) {
        mismaUnidad++;
        await pg.query(
          `UPDATE productos SET unidad_compra = NULL, unidad_compra_nombre = NULL, factor_unidad_compra = NULL WHERE id = $1`,
          [prodId]
        );
        continue;
      }

      await pg.query(`
        UPDATE productos
        SET unidad_compra        = $1,
            unidad_compra_nombre = $2,
            factor_unidad_compra = $3,
            updated_at           = NOW()
        WHERE id = $4
      `, [
        brilo.unidad_compra?.toLowerCase(),
        brilo.unidad_compra_nombre,
        Number(brilo.factor),
        prodId,
      ]);
      actualizados++;
    }
    await pg.query('COMMIT');
  } catch (e) {
    await pg.query('ROLLBACK');
    console.error('Error — ROLLBACK:', e.message);
    process.exit(1);
  }

  console.log(`\n✓ Actualizados con unidad_compra diferente: ${actualizados}`);
  console.log(`  Misma unidad (unidad base = default): ${mismaUnidad}`);
  console.log(`  Sin match en Brilo: ${sinMatch}`);

  // 4. Preview de los primeros 10 con unidad_compra
  const preview = await pg.query(`
    SELECT codigo, nombre, unidad, unidad_compra, unidad_compra_nombre, factor_unidad_compra
    FROM productos
    WHERE unidad_compra IS NOT NULL
    ORDER BY nombre
    LIMIT 10
  `);
  console.log('\nEjemplos:');
  preview.rows.forEach(r => {
    console.log(`  ${r.codigo} ${r.nombre.padEnd(40)} | stock: ${r.unidad} | compra: ${r.unidad_compra} (${r.unidad_compra_nombre}) x${r.factor_unidad_compra}`);
  });

  await pg.end();
  pool.close();
}

main().catch(e => console.error('Fatal:', e.message));
