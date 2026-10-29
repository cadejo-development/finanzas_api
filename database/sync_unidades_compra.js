require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const sql  = require('mssql');
const { Client } = require('pg');

const MSSQL_CFG = {
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_PASSWORD_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: 2033, database: 'olInventario',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000, requestTimeout: 60000 },
};

const PG_CFG = {
  host:     process.env.DB_HOST_COMPRAS,
  port:     5432,
  database: process.env.DB_DATABASE_COMPRAS,  // gestion_operaciones_db
  user:     process.env.DB_USERNAME_COMPRAS,
  password: process.env.DB_PASSWORD_COMPRAS,
  ssl: { rejectUnauthorized: false },
};

async function main() {
  console.log('Conectando a Brilo SQL Server...');
  const pool = await sql.connect(MSSQL_CFG);
  console.log('Conectando a PostgreSQL gestion_operaciones_db...');
  const pg = new Client(PG_CFG);
  await pg.connect();
  await pg.query(`SET search_path TO public`);
  console.log('Conectado.\n');

  // 1. Productos activos de PostgreSQL (schema compras)
  const { rows: pgProds } = await pg.query(
    `SELECT id, codigo FROM productos WHERE activo = true AND codigo IS NOT NULL`
  );
  const pgMap = {};
  pgProds.forEach(p => { pgMap[p.codigo.trim()] = p.id; });
  console.log(`Productos en catálogo PG: ${pgProds.length}`);

  // 2. Unidades default de compra desde Brilo
  console.log('Leyendo vwUnidadesXProductoYUniBase de Brilo...');
  const briloRes = await pool.request().query(`
    SELECT
      p.proCodigo        AS codigo,
      v.uniAbreviacion   AS unidad_compra,
      v.uniNombre        AS unidad_compra_nombre,
      v.unipCantidad     AS factor,
      v.uniBase          AS es_base
    FROM olComun.dbo.Productos p WITH(NOLOCK)
    JOIN olComun.dbo.vwUnidadesXProductoYUniBase v WITH(NOLOCK)
      ON v.proId = p.proId
     AND v.unipDefault = 1
     AND v.uniActiva   = 1
    WHERE p.proActivo = 1
  `);

  const briloMap = {};
  briloRes.recordset.forEach(r => { briloMap[r.codigo?.trim()] = r; });
  console.log(`Productos Brilo con unidad default: ${briloRes.recordset.length}\n`);

  // 3. Actualizar PostgreSQL
  let actualizados = 0, mismaUnidad = 0, sinMatch = 0;

  await pg.query('BEGIN');
  try {
    for (const [codigo, prodId] of Object.entries(pgMap)) {
      const brilo = briloMap[codigo];
      if (!brilo) { sinMatch++; continue; }

      if (brilo.es_base) {
        // La unidad de compra == unidad base → no hay presentación diferente
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
    console.log(`✓ Actualizados con empaque distinto: ${actualizados}`);
    console.log(`  Sin presentación de compra (misma unidad base): ${mismaUnidad}`);
    console.log(`  Sin match en Brilo: ${sinMatch}`);
  } catch (e) {
    await pg.query('ROLLBACK');
    console.error('Error — ROLLBACK:', e.message);
    process.exit(1);
  }

  // 4. Preview
  const preview = await pg.query(`
    SELECT codigo, nombre, unidad, unidad_compra, unidad_compra_nombre, factor_unidad_compra
    FROM productos
    WHERE unidad_compra IS NOT NULL
    ORDER BY nombre LIMIT 15
  `);
  console.log('\nEjemplos con empaque:');
  preview.rows.forEach(r => {
    console.log(`  ${r.codigo.padEnd(16)} ${r.nombre.substring(0,35).padEnd(36)} | base:${r.unidad} | compra:${r.unidad_compra} (${r.unidad_compra_nombre}) x${r.factor_unidad_compra}`);
  });

  await pg.end();
  pool.close();
}

main().catch(e => { console.error('Fatal:', e.message); process.exit(1); });
