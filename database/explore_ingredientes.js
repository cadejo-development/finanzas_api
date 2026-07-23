require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const sql      = require('mssql');
const { Pool } = require('pg');

const MSSQL_CFG = {
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_PASSWORD_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

const PG_CFG = {
  host: process.env.DB_HOST, port: 5432,
  database: 'compras_db', user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
};

async function main() {
  const pool = await sql.connect(MSSQL_CFG);
  const pg   = new Pool(PG_CFG);

  // Tomar 10 recetas con ingredientes de RDS, buscar sus mismas en SQL Server
  const pgIng = await pg.query(`
    SELECT ri.receta_id, ri.producto_id, ri.cantidad_por_plato, ri.unidad,
           r.codigo_origen AS receta_codigo,
           p.codigo_origen AS ingrediente_codigo, p.nombre AS ingrediente_nombre
    FROM receta_ingredientes ri
    JOIN recetas r ON r.id = ri.receta_id
    JOIN productos p ON p.id = ri.producto_id
    WHERE ri.cantidad_por_plato IS NOT NULL
    ORDER BY ri.updated_at DESC
    LIMIT 15
  `);

  console.log('=== Ingredientes en RDS vs BRILO ===\n');
  for (const row of pgIng.rows) {
    // Buscar en SQL Server: proId del producto terminado (receta_codigo) y del material (ingrediente_codigo)
    const ms = await pool.request().query(`
      SELECT m.mxprCantidad, m.uniId, m.mxprCantUnidad,
             u.uniNombre, u.uniAbreviacion,
             pr.proCodigo AS recetaCod, pm.proCodigo AS matCod
      FROM olComun.dbo.MaterialesXProducto m
      JOIN olComun.dbo.Productos pr ON pr.proId = m.proId AND TRIM(pr.proCodigo) = '${row.receta_codigo}'
      JOIN olComun.dbo.Productos pm ON pm.proId = m.proIdMaterial AND TRIM(pm.proCodigo) = '${row.ingrediente_codigo}'
      LEFT JOIN olComun.dbo.Unidades u ON u.uniId = m.uniId
      WHERE m.mxprEliminado = 0
    `);
    if (ms.recordset.length) {
      const m = ms.recordset[0];
      console.log(`Receta: ${row.receta_codigo} | Ing: ${row.ingrediente_codigo} (${row.ingrediente_nombre})`);
      console.log(`  RDS: cantidad=${row.cantidad_por_plato} ${row.unidad}`);
      console.log(`  SQL: mxprCantidad=${m.mxprCantidad}, uniId=${m.uniId}(${m.uniAbreviacion}), mxprCantUnidad=${m.mxprCantUnidad}`);
      console.log('');
    }
  }

  await pool.close();
  await pg.end();
}
main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
