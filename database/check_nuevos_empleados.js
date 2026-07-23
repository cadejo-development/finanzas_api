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
  database: 'core_db', user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
};

const DEP_MAP = {
  'C_Administración': 'SUC-CM', 'C_LOGISTICA': 'SUC-CM', 'C_MANTTO': 'SUC-CM',
  'C_Mercadeo': 'SUC-CM', 'C_Producción': 'SUC-CM', 'C_Produccion_R': 'SUC-CM',
  'C_Ventas': 'SUC-CM', 'DPT0001': 'SUC-CM', 'DPT0004': 'SUC-CM', 'PROD': 'SUC-CM',
  'RT-001': 'SUC-ZR', 'DPT0002': 'SUC-AE1', 'DPT0003': 'SUC-AE2',
  'MAL-AE': 'SUC-AE1', 'DPT0006': 'SUC-PV', 'DPT0007': 'SUC-SE',
  'DPT0008': 'SUC-HZ', 'DPT0009': 'SUC-OP', 'DTP00010': 'SUC-GU',
  'RT-003': 'SUC-LL',
};

async function main() {
  const pool = await sql.connect(MSSQL_CFG);
  const pg   = new Pool(PG_CFG);

  // Empleados activos en SQL Server
  const ssRes = await pool.request().query(`
    SELECT e.empCodigo, e.empNombres, e.empApellidos, d.depCodigo, d.depNombre
    FROM olComun.dbo.Empleados e
    LEFT JOIN olComun.dbo.Deptos d ON d.depId = e.depId
    WHERE e.empActivo = 1 AND e.empEliminado = 0 AND e.empCodigo IS NOT NULL
    ORDER BY e.empCodigo
  `);
  const ssEmps = ssRes.recordset;

  // Empleados en RDS
  const pgRes = await pg.query(`SELECT codigo FROM empleados`);
  const pgCodigos = new Set(pgRes.rows.map(r => r.codigo));

  // Sucursales en RDS para resolver nombres
  const sucRes = await pg.query(`SELECT id, codigo, nombre FROM sucursales`);
  const sucMap = {};
  sucRes.rows.forEach(r => { sucMap[r.codigo] = r.nombre; });

  // Filtrar nuevos
  const nuevos = ssEmps.filter(e => !pgCodigos.has(e.empCodigo));

  console.log(`\nEmpleados en SQL Server: ${ssEmps.length}`);
  console.log(`Empleados en RDS:        ${pgCodigos.size}`);
  console.log(`Nuevos a insertar:       ${nuevos.length}\n`);

  if (nuevos.length === 0) {
    console.log('✓ No hay empleados nuevos.');
  } else {
    console.log('=== Empleados NUEVOS ===');
    nuevos.forEach(e => {
      const sucCodigo = DEP_MAP[e.depCodigo] ?? null;
      const sucNombre = sucCodigo ? (sucMap[sucCodigo] ?? sucCodigo) : `Sin mapeo (${e.depCodigo ?? 'sin depto'})`;
      console.log(`  ${e.empCodigo} | ${e.empNombres} ${e.empApellidos} | ${sucNombre}`);
    });
  }

  await pool.close();
  await pg.end();
}
main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
