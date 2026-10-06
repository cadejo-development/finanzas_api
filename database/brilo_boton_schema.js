require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const sql = require('mssql');

const cfg = {
  server: process.env.DB_HOST_ORIGEN, port: parseInt(process.env.DB_PORT_ORIGEN) || 2033,
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_PASSWORD_ORIGEN,
  database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false }, connectionTimeout: 15000, requestTimeout: 30000,
};

async function main() {
  const pool = await sql.connect(cfg);

  // Columnas de Productos para buscar campo "botón"
  console.log('=== Columnas de Productos (olcomun) ===');
  const cols = await pool.request().query(`
    SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_CATALOG = 'olcomun' AND TABLE_NAME = 'Productos'
    ORDER BY ORDINAL_POSITION
  `);
  cols.recordset.forEach(r => console.log(`  ${r.COLUMN_NAME} (${r.DATA_TYPE})`));

  // Columnas de ProductoXCocinaXSucRst
  console.log('\n=== Columnas de ProductoXCocinaXSucRst (olRestaurante) ===');
  const cols2 = await pool.request().query(`
    SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_CATALOG = 'olRestaurante' AND TABLE_NAME = 'ProductoXCocinaXSucRst'
    ORDER BY ORDINAL_POSITION
  `);
  cols2.recordset.forEach(r => console.log(`  ${r.COLUMN_NAME} (${r.DATA_TYPE})`));

  // Ver una fila de muestra de ProductoXCocinaXSucRst
  console.log('\n=== Muestra ProductoXCocinaXSucRst (3 filas PL%) ===');
  const sample = await pool.request().query(`
    SELECT TOP 3 px.*, p.proCodigo, p.proNombre
    FROM olRestaurante.dbo.ProductoXCocinaXSucRst px
    JOIN olcomun.dbo.Productos p ON p.proId = px.proId
    WHERE p.proCodigo LIKE 'PL%'
  `);
  sample.recordset.forEach(r => console.log(JSON.stringify(r)));

  // Buscar tablas en olRestaurante con boton/button/cocina en el nombre
  console.log('\n=== Todas las tablas de olRestaurante ===');
  const tabs = await pool.request().query(`
    SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_CATALOG = 'olRestaurante'
    ORDER BY TABLE_NAME
  `);
  tabs.recordset.forEach(r => console.log(`  ${r.TABLE_NAME}`));

  await pool.close();
}
main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
