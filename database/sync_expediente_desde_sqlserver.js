/**
 * sync_expediente_desde_sqlserver.js
 *
 * SQL Server (olcomun) → RDS rrhh_db (expediente_datos_personales)
 *
 * Sincroniza datos personales del expediente que vienen de SQL Server:
 *   - genero          (empSexo M/F → masculino/femenino)
 *   - fecha_nacimiento (empFechaNacimiento)
 *
 * Reglas:
 *   - Si el empleado ya tiene el campo definido en rrhh_db → NO se toca.
 *   - Si el registro de expediente no existe → se inserta con los datos disponibles.
 *   - Si el registro existe pero el campo está NULL → se actualiza solo ese campo.
 *
 * Uso:
 *   node sync_expediente_desde_sqlserver.js             (ejecuta)
 *   node sync_expediente_desde_sqlserver.js --dry-run   (muestra qué haría, sin cambios)
 */

const sql      = require('mssql');
const { Pool } = require('pg');

// ── SQL Server ────────────────────────────────────────────────────────────────
const MSSQL_CFG = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

// ── RDS PostgreSQL core_db (para obtener IDs de empleados por código) ─────────
const PG_CORE = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'core_db', user: 'cadejo_admin', password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
};

// ── RDS PostgreSQL rrhh_db (expediente_datos_personales) ─────────────────────
const PG_RRHH = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'rrhh_db', user: 'cadejo_admin', password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
};

const DRY_RUN = process.argv.includes('--dry-run');
const NOW     = new Date().toISOString();
const AUD     = 'sync_expediente_desde_sqlserver.js';

const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s  => console.log(`[${ts()}] ${s}`);

// Mapeo empSexo → genero en rrhh_db
const SEXO_MAP = { M: 'masculino', F: 'femenino' };

// ─────────────────────────────────────────────────────────────────────────────
async function run() {
  if (DRY_RUN) log('=== MODO DRY-RUN: sin cambios en BD ===\n');

  // ── 1. Conectar SQL Server ────────────────────────────────────────────────
  log('Conectando a SQL Server...');
  const mssqlPool = await sql.connect(MSSQL_CFG);
  log('SQL Server OK.');

  // ── 2. Conectar PostgreSQL (core + rrhh) ──────────────────────────────────
  log('Conectando a PostgreSQL...');
  const pgCore = new Pool(PG_CORE);
  const pgRrhh = new Pool(PG_RRHH);
  await Promise.all([pgCore.query('SELECT 1'), pgRrhh.query('SELECT 1')]);
  log('PostgreSQL OK.\n');

  try {
    // ── 3. Leer datos desde SQL Server ────────────────────────────────────────
    log('Leyendo empleados desde SQL Server...');
    const { recordset } = await mssqlPool.request().query(`
      SELECT
        RTRIM(e.empCodigo)       AS codigo,
        RTRIM(e.empSexo)         AS sexo,
        e.empFechaNacimiento     AS fecha_nacimiento
      FROM olComun.dbo.Empleados e WITH (NOLOCK)
      WHERE e.empActivo = 1
        AND e.empCodigo IS NOT NULL
        AND (e.empSexo IS NOT NULL OR e.empFechaNacimiento IS NOT NULL)
    `);
    log(`SQL Server: ${recordset.length} empleados con sexo/fecha_nacimiento\n`);

    // ── 4. Mapear código → empleado_id desde core_db ──────────────────────
    log('Cargando IDs de empleados desde core_db...');
    const { rows: empRows } = await pgCore.query(
      'SELECT id, codigo FROM empleados WHERE activo = true'
    );
    const idPorCodigo = Object.fromEntries(empRows.map(r => [r.codigo, r.id]));
    log(`core_db: ${empRows.length} empleados activos\n`);

    // ── 5. Leer registros existentes en expediente_datos_personales ──────────
    log('Cargando expediente_datos_personales desde rrhh_db...');
    const { rows: expRows } = await pgRrhh.query(
      'SELECT empleado_id, genero, fecha_nacimiento FROM expediente_datos_personales'
    );
    // Mapa: empleado_id → { genero, fecha_nacimiento }
    const expPorId = Object.fromEntries(
      expRows.map(r => [r.empleado_id, { genero: r.genero, fecha_nacimiento: r.fecha_nacimiento }])
    );
    log(`rrhh_db: ${expRows.length} registros existentes\n`);

    // ── 6. Calcular qué insertar / actualizar ─────────────────────────────────
    const paraInsertar  = [];  // { empleado_id, genero, fecha_nacimiento }
    const paraActualizar = []; // { empleado_id, genero?, fecha_nacimiento? }
    let sinMatch = 0;

    for (const row of recordset) {
      const empId = idPorCodigo[row.codigo];
      if (!empId) { sinMatch++; continue; }

      const generoNuevo = row.sexo ? (SEXO_MAP[row.sexo] ?? null) : null;
      const fechaNueva  = row.fecha_nacimiento
        ? new Date(row.fecha_nacimiento).toISOString().split('T')[0]
        : null;

      const exp = expPorId[empId];

      if (!exp) {
        // No existe registro en expediente → insertar
        if (generoNuevo || fechaNueva) {
          paraInsertar.push({ empleado_id: empId, genero: generoNuevo, fecha_nacimiento: fechaNueva });
        }
      } else {
        // Existe → actualizar solo campos NULL
        const campos = {};
        if (!exp.genero          && generoNuevo) campos.genero          = generoNuevo;
        if (!exp.fecha_nacimiento && fechaNueva)  campos.fecha_nacimiento = fechaNueva;
        if (Object.keys(campos).length) {
          paraActualizar.push({ empleado_id: empId, ...campos });
        }
      }
    }

    log('──────────────────────────────────────────');
    log(`Por INSERTAR (sin registro previo): ${paraInsertar.length}`);
    log(`Por ACTUALIZAR (campos en NULL):    ${paraActualizar.length}`);
    log(`Sin match en core_db:               ${sinMatch}`);
    log('──────────────────────────────────────────\n');

    if (DRY_RUN) {
      if (paraInsertar.length) {
        log('Muestra de inserciones (primeras 5):');
        paraInsertar.slice(0, 5).forEach(r =>
          log(`  emp_id=${r.empleado_id} genero=${r.genero} fecha_nac=${r.fecha_nacimiento}`)
        );
      }
      if (paraActualizar.length) {
        log('Muestra de actualizaciones (primeras 5):');
        paraActualizar.slice(0, 5).forEach(r =>
          log(`  emp_id=${r.empleado_id} → ${JSON.stringify(r)}`)
        );
      }
      log('\n(dry-run) Sin cambios. Quita --dry-run para aplicar.');
      return;
    }

    // ── 7. Ejecutar inserciones ───────────────────────────────────────────────
    let insOk = 0;
    for (const r of paraInsertar) {
      await pgRrhh.query(
        `INSERT INTO expediente_datos_personales
           (empleado_id, genero, fecha_nacimiento, aud_usuario, created_at, updated_at)
         VALUES ($1, $2, $3, $4, $5, $5)
         ON CONFLICT (empleado_id) DO NOTHING`,
        [r.empleado_id, r.genero, r.fecha_nacimiento, AUD, NOW]
      );
      insOk++;
      process.stdout.write(`\r  Insertados: ${insOk}/${paraInsertar.length} `);
    }
    if (paraInsertar.length) console.log();

    // ── 8. Ejecutar actualizaciones ────────────────────────────────────────────
    let updOk = 0;
    for (const r of paraActualizar) {
      const sets   = [];
      const params = [];
      if (r.genero)          { params.push(r.genero);          sets.push(`genero = $${params.length}`); }
      if (r.fecha_nacimiento){ params.push(r.fecha_nacimiento); sets.push(`fecha_nacimiento = $${params.length}`); }
      params.push(AUD);  sets.push(`aud_usuario = $${params.length}`);
      params.push(NOW);  sets.push(`updated_at  = $${params.length}`);
      params.push(r.empleado_id);

      await pgRrhh.query(
        `UPDATE expediente_datos_personales SET ${sets.join(', ')} WHERE empleado_id = $${params.length}`,
        params
      );
      updOk++;
      process.stdout.write(`\r  Actualizados: ${updOk}/${paraActualizar.length} `);
    }
    if (paraActualizar.length) console.log();

    // ── 9. Resumen final ──────────────────────────────────────────────────────
    const { rows: res } = await pgRrhh.query(`
      SELECT
        COUNT(*) FILTER (WHERE genero = 'masculino') AS masculino,
        COUNT(*) FILTER (WHERE genero = 'femenino')  AS femenino,
        COUNT(*) FILTER (WHERE genero IS NULL)        AS sin_genero
      FROM expediente_datos_personales
    `);
    log('\n==========================================');
    log('RESUMEN FINAL');
    log(`  Insertados:           ${insOk}`);
    log(`  Actualizados:         ${updOk}`);
    log(`  Masculino en exp.:    ${res[0].masculino}`);
    log(`  Femenino  en exp.:    ${res[0].femenino}`);
    log(`  Sin género en exp.:   ${res[0].sin_genero}`);
    log('==========================================');

  } finally {
    await Promise.all([mssqlPool.close(), pgCore.end(), pgRrhh.end()]).catch(() => {});
    log('Conexiones cerradas.');
  }
}

run().catch(err => {
  console.error('\nERROR:', err.message ?? err);
  process.exit(1);
});
