require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const sql = require('mssql');
const { Client } = require('pg');

const MSSQL_CFG = {
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_PASSWORD_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: 2033, database: 'olComun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000, requestTimeout: 60000 },
};
const PG_CFG = {
  host: process.env.DB_HOST_COMPRAS, port: 5432,
  database: process.env.DB_DATABASE_COMPRAS,
  user: process.env.DB_USERNAME_COMPRAS, password: process.env.DB_PASSWORD_COMPRAS,
  ssl: { rejectUnauthorized: false },
};

async function main() {
  const pool = await sql.connect(MSSQL_CFG);
  const pg   = new Client(PG_CFG);
  await pg.connect();

  // Todos los MR de Brilo (activos e inactivos) con su estado
  const briloMR = await pool.request().query(`
    SELECT proCodigo, proNombre, proActivo, proTipo
    FROM dbo.Productos WHERE proCodigo LIKE 'MR%'
  `);
  const briloMap = {};
  briloMR.recordset.forEach(r => { briloMap[r.proCodigo?.trim()] = r; });

  const briloActivos   = briloMR.recordset.filter(r => r.proActivo).length;
  const briloInactivos = briloMR.recordset.filter(r => !r.proActivo).length;
  console.log(`Brilo MR activos:   ${briloActivos}`);
  console.log(`Brilo MR inactivos: ${briloInactivos}`);
  console.log(`Brilo MR total:     ${briloMR.recordset.length}`);

  // MR en RDS activos
  const { rows: rdsMR } = await pg.query(`
    SELECT p.id, p.codigo, p.nombre, p.activo
    FROM productos p
    JOIN categorias c ON c.id = p.categoria_id
    WHERE c.key ILIKE 'MR%' AND p.activo = true AND p.codigo IS NOT NULL
    ORDER BY p.codigo
  `);
  console.log(`\nRDS MR activos: ${rdsMR.length}`);

  // Cruzar
  let activosEnAmbos      = 0;
  let activoRdsInactivoBrilo = 0;
  let sinMatchBrilo       = 0;
  const ejemplosInactBrilo = [];

  rdsMR.forEach(p => {
    const b = briloMap[p.codigo?.trim()];
    if (!b) {
      sinMatchBrilo++;
    } else if (b.proActivo) {
      activosEnAmbos++;
    } else {
      activoRdsInactivoBrilo++;
      if (ejemplosInactBrilo.length < 10) ejemplosInactBrilo.push(p);
    }
  });

  console.log(`\n  Activos en RDS y activos en Brilo:      ${activosEnAmbos}`);
  console.log(`  Activos en RDS pero INACTIVOS en Brilo:  ${activoRdsInactivoBrilo}  ← estos sobran`);
  console.log(`  Sin código en Brilo (custom/nuevos):     ${sinMatchBrilo}`);

  console.log('\nEjemplos activos en RDS pero inactivos en Brilo:');
  ejemplosInactBrilo.forEach(p => console.log(`  ${p.codigo.padEnd(18)} ${p.nombre}`));

  // Cuántos activos en Brilo (proTipo=0) pero inactivos en RDS — para ver si sync está atrasado
  const codigosRdsActivos = new Set(rdsMR.map(p => p.codigo?.trim()));
  const briloActivosInvNoEnRds = briloMR.recordset.filter(r =>
    r.proActivo && r.proTipo === 0 && !codigosRdsActivos.has(r.proCodigo?.trim())
  );
  console.log(`\nActivos inventariados en Brilo que NO están en RDS: ${briloActivosInvNoEnRds.length}`);
  briloActivosInvNoEnRds.slice(0,5).forEach(r => console.log(`  ${r.proCodigo} ${r.proNombre}`));

  await pg.end();
  pool.close();
}
main().catch(e => { console.error('Fatal:', e.message); process.exit(1); });
