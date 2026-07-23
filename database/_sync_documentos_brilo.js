/**
 * Sync de documentos desde Brilo → nuestra BD (rrhh_db)
 *
 * Por cada empleado activo en Brilo, inserta en expediente_documentos
 * los registros de ISSS, AFP y NIT que aún no existan, y actualiza
 * la profesión en expediente_datos_personales si está vacía.
 *
 * Uso: node database/_sync_documentos_brilo.js [--dry-run]
 */

const sql        = require('mssql');
const { Client } = require('pg');

const DRY_RUN = process.argv.includes('--dry-run');

const RDS_HOST = 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com';
const RDS_USER = 'cadejo_admin';
const RDS_PASS = 'Holamundo#3..';

const briloConfig = {
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  user: 'olimporeader', password: 'olimporeader',
  options: { encrypt: false, trustServerCertificate: true, connectTimeout: 60000, requestTimeout: 60000 },
};

const pgConfig = {
  host: RDS_HOST, port: 5432, database: 'rrhh_db',
  user: RDS_USER, password: RDS_PASS,
  ssl:  { rejectUnauthorized: false },
};

const pgCore = {
  host: RDS_HOST, port: 5432, database: 'core_db',
  user: RDS_USER, password: RDS_PASS,
  ssl:  { rejectUnauthorized: false },
};

// Mapa iprId → nombre de AFP
const AFP_NAMES = { 4: 'AFP CRECER', 5: 'AFP CONFIA', 6: 'IPSFA', 9: 'ISSS-IVM' };

async function main() {
  console.log(`=== Sync documentos Brilo → RDS ${DRY_RUN ? '[DRY RUN]' : ''} ===\n`);

  const brilo  = await sql.connect(briloConfig);
  const pgRrhh = new Client(pgConfig);
  const pgMain = new Client(pgCore);
  await pgRrhh.connect();
  await pgMain.connect();

  // 1. Obtener todos los activos de Brilo con sus documentos
  const { recordset: briloEmps } = await brilo.request().query(`
    SELECT e.empCodigo, e.empISSS, e.empNumIPR, e.empNIT, e.empProfesionDUI, e.iprId, ipr.iprNombreAbr
    FROM Empleados e
    LEFT JOIN InstPrevisional ipr ON ipr.iprId = e.iprId
    WHERE e.empActivo = 1
  `);
  console.log(`Empleados activos en Brilo: ${briloEmps.length}`);

  // 2. Obtener mapa codigo → id de nuestra BD
  const { rows: nuestrosEmps } = await pgMain.query('SELECT id, codigo FROM empleados WHERE activo = true AND codigo IS NOT NULL');
  const codigoToId = {};
  nuestrosEmps.forEach(e => { codigoToId[String(e.codigo).trim()] = e.id; });

  // 3. Documentos ya existentes en rrhh (para no duplicar)
  const { rows: docsExistentes } = await pgRrhh.query(
    "SELECT empleado_id, tipo FROM expediente_documentos"
  );
  const docSet = new Set(docsExistentes.map(d => `${d.empleado_id}:${d.tipo}`));

  // 4. Datos personales existentes (para profesión)
  const { rows: dpExistentes } = await pgRrhh.query(
    "SELECT empleado_id, profesion FROM expediente_datos_personales"
  );
  const dpMap = {};
  dpExistentes.forEach(d => { dpMap[d.empleado_id] = d.profesion; });

  let inserts = 0, profActualiz = 0, sinMatch = 0;

  for (const emp of briloEmps) {
    const codigo = String(emp.empCodigo || '').trim();
    const empId  = codigoToId[codigo];

    if (!empId) { sinMatch++; continue; }

    const ahora = new Date().toISOString();

    // ISSS
    if (emp.empISSS && emp.empISSS.trim() && !docSet.has(`${empId}:isss`)) {
      if (!DRY_RUN) {
        await pgRrhh.query(
          `INSERT INTO expediente_documentos (empleado_id, tipo, numero, notas, created_at, updated_at)
           VALUES ($1, 'isss', $2, 'sync:brilo', $3, $3)`,
          [empId, emp.empISSS.trim(), ahora]
        );
      }
      console.log(`  + ISSS ${empId} (${codigo}): ${emp.empISSS.trim()}`);
      docSet.add(`${empId}:isss`);
      inserts++;
    }

    // AFP/NUP
    if (emp.empNumIPR && emp.empNumIPR.trim() && !docSet.has(`${empId}:afp`)) {
      const afpNombre = emp.iprNombreAbr || AFP_NAMES[emp.iprId] || 'AFP';
      if (!DRY_RUN) {
        await pgRrhh.query(
          `INSERT INTO expediente_documentos (empleado_id, tipo, numero, entidad_emisora, notas, created_at, updated_at)
           VALUES ($1, 'afp', $2, $3, 'sync:brilo', $4, $4)`,
          [empId, emp.empNumIPR.trim(), afpNombre, ahora]
        );
      }
      console.log(`  + AFP  ${empId} (${codigo}): ${emp.empNumIPR.trim()} (${afpNombre})`);
      docSet.add(`${empId}:afp`);
      inserts++;
    }

    // NIT
    if (emp.empNIT && emp.empNIT.trim() && !docSet.has(`${empId}:nit`)) {
      if (!DRY_RUN) {
        await pgRrhh.query(
          `INSERT INTO expediente_documentos (empleado_id, tipo, numero, notas, created_at, updated_at)
           VALUES ($1, 'nit', $2, 'sync:brilo', $3, $3)`,
          [empId, emp.empNIT.trim(), ahora]
        );
      }
      console.log(`  + NIT  ${empId} (${codigo}): ${emp.empNIT.trim()}`);
      docSet.add(`${empId}:nit`);
      inserts++;
    }

    // Profesión
    const profBrilo = (emp.empProfesionDUI || '').trim();
    if (profBrilo && empId in dpMap && !dpMap[empId]?.trim()) {
      if (!DRY_RUN) {
        await pgRrhh.query(
          `UPDATE expediente_datos_personales SET profesion = $1, updated_at = $2 WHERE empleado_id = $3`,
          [profBrilo, ahora, empId]
        );
      }
      console.log(`  ~ PROF ${empId} (${codigo}): "${profBrilo}"`);
      dpMap[empId] = profBrilo;
      profActualiz++;
    }
  }

  await brilo.close();
  await pgRrhh.end();
  await pgMain.end();

  console.log(`\n=== Resumen ===`);
  console.log(`Documentos insertados : ${inserts}`);
  console.log(`Profesiones actualizadas: ${profActualiz}`);
  console.log(`Sin match en nuestra BD : ${sinMatch}`);
  if (DRY_RUN) console.log('\n[DRY RUN — no se escribió nada]');
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
