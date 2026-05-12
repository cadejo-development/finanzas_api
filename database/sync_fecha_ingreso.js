/**
 * sync_fecha_ingreso.js
 *
 * SQL Server (olcomun) → RDS PostgreSQL (core_db)
 *
 * Solo actualiza el campo fecha_ingreso en los empleados de RDS
 * que aún no lo tienen (o --todos para sobreescribir todos).
 *
 * Match: empleados.codigo ↔ empCodigo
 *
 * Uso:
 *   node sync_fecha_ingreso.js             (solo los que tienen NULL)
 *   node sync_fecha_ingreso.js --todos     (sobreescribe aunque ya tengan fecha)
 *   node sync_fecha_ingreso.js --dry-run   (muestra qué haría, sin escribir)
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
  database: 'core_db', user: 'cadejo_admin',
  password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
};

const DRY_RUN = process.argv.includes('--dry-run');
const TODOS   = process.argv.includes('--todos');

const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s => console.log(`[${ts()}] ${s}`);

async function run() {
  if (DRY_RUN) log('=== DRY-RUN: no se escribirá nada ===');
  if (TODOS)   log('=== --todos: sobreescribe aunque ya tengan fecha ===');

  log('Conectando SQL Server...');
  const pool = await sql.connect(MSSQL_CFG);
  log('SQL Server OK.');

  log('Conectando PostgreSQL RDS...');
  const pg = new Pool(PG_CFG);
  await pg.query('SELECT 1');
  log('PostgreSQL OK.\n');

  try {
    // ── 1. Traer empleados con fecha de ingreso desde SQL Server ──────────────
    log('Consultando SQL Server...');
    const { recordset } = await pool.request().query(`
      SELECT
        e.empCodigo        AS codigo,
        e.empFechaIngreso  AS fecha_ingreso
      FROM olComun.dbo.Empleados e WITH (NOLOCK)
      WHERE e.empCodigo IS NOT NULL
        AND e.empFechaIngreso IS NOT NULL
      ORDER BY e.empCodigo
    `);
    log(`SQL Server: ${recordset.length} empleados con fecha de ingreso.`);

    // ── 2. Traer empleados de RDS para hacer el match ─────────────────────────
    const whereClause = TODOS ? '' : 'WHERE fecha_ingreso IS NULL';
    const { rows: rdsEmpleados } = await pg.query(
      `SELECT id, codigo FROM empleados ${whereClause} ORDER BY codigo`
    );
    log(`RDS: ${rdsEmpleados.length} empleados ${TODOS ? 'en total' : 'sin fecha de ingreso'}.`);

    // Índice rápido por codigo
    const rdsPorCodigo = {};
    for (const e of rdsEmpleados) rdsPorCodigo[String(e.codigo)] = e.id;

    // ── 3. Cruzar y actualizar ────────────────────────────────────────────────
    let actualizados = 0, sinMatch = 0, sinFecha = 0;
    const noEncontrados = [];

    for (const row of recordset) {
      const codigo = String(row.codigo).trim();
      const id     = rdsPorCodigo[codigo];

      if (!id) {
        sinMatch++;
        noEncontrados.push(codigo);
        continue;
      }

      const fecha = row.fecha_ingreso instanceof Date
        ? row.fecha_ingreso.toISOString().slice(0, 10)
        : String(row.fecha_ingreso).slice(0, 10);

      if (!fecha || fecha === 'null') { sinFecha++; continue; }

      log(`  ${DRY_RUN ? '[DRY]' : ''} Empleado ${codigo} (id=${id}) → ${fecha}`);

      if (!DRY_RUN) {
        await pg.query(
          `UPDATE empleados SET fecha_ingreso = $1, updated_at = NOW() WHERE id = $2`,
          [fecha, id]
        );
      }
      actualizados++;
    }

    // ── 4. Resumen ────────────────────────────────────────────────────────────
    console.log('');
    log('=== RESUMEN ===');
    log(`  Actualizados:   ${actualizados}`);
    log(`  Sin match RDS:  ${sinMatch}`);
    log(`  Sin fecha en SS: ${sinFecha}`);
    if (noEncontrados.length) {
      log(`  Códigos no encontrados en RDS: ${noEncontrados.join(', ')}`);
    }

  } finally {
    await pool.close();
    await pg.end();
  }
}

run().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
