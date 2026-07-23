/**
 * Sync de documentos desde Brilo → nuestra BD (rrhh_db)
 *
 * Por cada empleado activo en Brilo:
 *   - Inserta ISSS, AFP, NIT si no existen en expediente_documentos
 *   - Actualiza lugar y fecha de emisión del DUI si están vacíos
 *   - Actualiza profesión en expediente_datos_personales si está vacía
 *   - Actualiza domicilio (municipio, departamento) si está vacío
 *   - Crea una entrada en expediente_direcciones si el empleado no tiene ninguna
 *
 * Uso: node database/_sync_documentos_brilo.js [--dry-run]
 */

require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const sql        = require('mssql');
const { Client } = require('pg');

const DRY_RUN = process.argv.includes('--dry-run');

const briloConfig = {
  server:   process.env.DB_HOST_ORIGEN,
  port:     Number(process.env.DB_PORT_ORIGEN) || 2033,
  database: process.env.DB_DATABASE_ORIGEN,
  user:     process.env.DB_USERNAME_ORIGEN,
  password: process.env.DB_PASSWORD_ORIGEN,
  options: { encrypt: false, trustServerCertificate: true, connectTimeout: 60000, requestTimeout: 60000 },
};

const pgRrhhConfig = {
  host:     process.env.DB_HOST_RRHH,
  port:     Number(process.env.DB_PORT_RRHH) || 5432,
  database: process.env.DB_DATABASE_RRHH,
  user:     process.env.DB_USERNAME_RRHH,
  password: process.env.DB_PASSWORD_RRHH,
  ssl:      { rejectUnauthorized: false },
};

const pgCoreConfig = {
  host:     process.env.DB_HOST,
  port:     Number(process.env.DB_PORT) || 5432,
  database: process.env.DB_DATABASE,
  user:     process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  ssl:      { rejectUnauthorized: false },
};

// Mapa iprId → nombre de AFP
const AFP_NAMES = { 4: 'AFP CRECER', 5: 'AFP CONFIA', 6: 'IPSFA', 9: 'ISSS-IVM' };

async function main() {
  console.log(`=== Sync documentos Brilo → RDS ${DRY_RUN ? '[DRY RUN]' : ''} ===\n`);

  const brilo  = await sql.connect(briloConfig);
  const pgRrhh = new Client(pgRrhhConfig);
  const pgMain = new Client(pgCoreConfig);
  await pgRrhh.connect();
  await pgMain.connect();

  // 1. Empleados activos de Brilo con todos los campos necesarios
  const { recordset: briloEmps } = await brilo.request().query(`
    SELECT
      e.empCodigo,
      e.empISSS, e.empNumIPR, e.empNIT,
      e.empProfesionDUI,
      e.iprId, ipr.iprNombreAbr,
      e.empDUI, e.empDuiFechaExpedicion,
      e.muniIdDuiExpedicion,
      mExp.muniNombre  AS duiMuni,
      deExp.dptoNombre AS duiDpto,
      e.muniIdResidencia,
      mRes.muniNombre  AS resMuni,
      deRes.dptoNombre AS resDpto,
      e.empDireccion
    FROM Empleados e
    LEFT JOIN InstPrevisional  ipr   ON ipr.iprId   = e.iprId
    LEFT JOIN MuniCondados     mExp  ON mExp.muniId  = e.muniIdDuiExpedicion
    LEFT JOIN DeptosEstados    deExp ON deExp.dptoId = mExp.dptoId
    LEFT JOIN MuniCondados     mRes  ON mRes.muniId  = e.muniIdResidencia
    LEFT JOIN DeptosEstados    deRes ON deRes.dptoId = mRes.dptoId
    WHERE e.empActivo = 1
  `);
  console.log(`Empleados activos en Brilo: ${briloEmps.length}`);

  // 2. Mapa codigo → id de nuestra BD
  const { rows: nuestrosEmps } = await pgMain.query(
    'SELECT id, codigo FROM empleados WHERE activo = true AND codigo IS NOT NULL'
  );
  const codigoToId = {};
  nuestrosEmps.forEach(e => { codigoToId[String(e.codigo).trim()] = e.id; });

  // 3. Documentos existentes (tipo+empleado para no duplicar; datos de DUI para actualizar)
  const { rows: docsExistentes } = await pgRrhh.query(
    "SELECT id, empleado_id, tipo, lugar_exp_texto, fecha_emision FROM expediente_documentos"
  );
  const docSet = new Set(docsExistentes.map(d => `${d.empleado_id}:${d.tipo}`));
  const duiMap = {}; // empId → {id, lugar_exp_texto, fecha_emision}
  docsExistentes.filter(d => d.tipo === 'dui').forEach(d => {
    duiMap[d.empleado_id] = { id: d.id, lugar: d.lugar_exp_texto, fecha: d.fecha_emision };
  });

  // 4. Datos personales (profesion, domicilio)
  const { rows: dpRows } = await pgRrhh.query(
    "SELECT empleado_id, profesion, domicilio FROM expediente_datos_personales"
  );
  const dpMap = {};
  dpRows.forEach(d => { dpMap[d.empleado_id] = { profesion: d.profesion, domicilio: d.domicilio }; });

  // 5. Empleados con al menos una dirección registrada
  const { rows: dirRows } = await pgRrhh.query(
    "SELECT DISTINCT empleado_id FROM expediente_direcciones"
  );
  const tieneDir = new Set(dirRows.map(d => d.empleado_id));

  let docs = 0, duiActualiz = 0, profActualiz = 0, domActualiz = 0, dirInserts = 0, sinMatch = 0;

  for (const emp of briloEmps) {
    const codigo = String(emp.empCodigo || '').trim();
    const empId  = codigoToId[codigo];
    if (!empId) { sinMatch++; continue; }

    const ahora = new Date().toISOString();

    // ── ISSS ─────────────────────────────────────────────────────────────────
    if (emp.empISSS?.trim() && !docSet.has(`${empId}:isss`)) {
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_documentos (empleado_id, tipo, numero, notas, created_at, updated_at)
         VALUES ($1, 'isss', $2, 'sync:brilo', $3, $3)`,
        [empId, emp.empISSS.trim(), ahora]
      );
      console.log(`  + ISSS  ${empId} (${codigo}): ${emp.empISSS.trim()}`);
      docSet.add(`${empId}:isss`);
      docs++;
    }

    // ── AFP ──────────────────────────────────────────────────────────────────
    if (emp.empNumIPR?.trim() && !docSet.has(`${empId}:afp`)) {
      const afpNombre = emp.iprNombreAbr || AFP_NAMES[emp.iprId] || 'AFP';
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_documentos (empleado_id, tipo, numero, entidad_emisora, notas, created_at, updated_at)
         VALUES ($1, 'afp', $2, $3, 'sync:brilo', $4, $4)`,
        [empId, emp.empNumIPR.trim(), afpNombre, ahora]
      );
      console.log(`  + AFP   ${empId} (${codigo}): ${emp.empNumIPR.trim()} (${afpNombre})`);
      docSet.add(`${empId}:afp`);
      docs++;
    }

    // ── NIT ──────────────────────────────────────────────────────────────────
    if (emp.empNIT?.trim() && !docSet.has(`${empId}:nit`)) {
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_documentos (empleado_id, tipo, numero, notas, created_at, updated_at)
         VALUES ($1, 'nit', $2, 'sync:brilo', $3, $3)`,
        [empId, emp.empNIT.trim(), ahora]
      );
      console.log(`  + NIT   ${empId} (${codigo}): ${emp.empNIT.trim()}`);
      docSet.add(`${empId}:nit`);
      docs++;
    }

    // ── DUI: lugar y fecha de emisión ─────────────────────────────────────────
    if (duiMap[empId]) {
      const dui = duiMap[empId];
      const lugarBrilo = [emp.duiMuni, emp.duiDpto].filter(Boolean).join(', ');
      const fechaBrilo = emp.empDuiFechaExpedicion
        ? new Date(emp.empDuiFechaExpedicion).toISOString().split('T')[0]
        : null;

      const needsLugar = lugarBrilo && !dui.lugar?.trim();
      const needsFecha = fechaBrilo && !dui.fecha;

      if (needsLugar || needsFecha) {
        if (!DRY_RUN) await pgRrhh.query(
          `UPDATE expediente_documentos
           SET lugar_exp_texto = COALESCE(NULLIF(lugar_exp_texto,''), $1),
               fecha_emision   = COALESCE(fecha_emision, $2),
               updated_at      = $3
           WHERE id = $4`,
          [lugarBrilo || null, fechaBrilo || null, ahora, dui.id]
        );
        console.log(`  ~ DUI   ${empId} (${codigo}): lugar="${lugarBrilo}" fecha=${fechaBrilo}`);
        duiActualiz++;
      }
    }

    // ── Profesión ─────────────────────────────────────────────────────────────
    const profBrilo = (emp.empProfesionDUI || '').trim();
    if (profBrilo && dpMap[empId] && !dpMap[empId].profesion?.trim()) {
      if (!DRY_RUN) await pgRrhh.query(
        `UPDATE expediente_datos_personales SET profesion=$1, updated_at=$2 WHERE empleado_id=$3`,
        [profBrilo, ahora, empId]
      );
      console.log(`  ~ PROF  ${empId} (${codigo}): "${profBrilo}"`);
      dpMap[empId].profesion = profBrilo;
      profActualiz++;
    }

    // ── Domicilio ─────────────────────────────────────────────────────────────
    const domBrilo = [emp.resMuni, emp.resDpto].filter(Boolean).join(', ');
    if (domBrilo && dpMap[empId] && !dpMap[empId].domicilio?.trim()) {
      if (!DRY_RUN) await pgRrhh.query(
        `UPDATE expediente_datos_personales SET domicilio=$1, updated_at=$2 WHERE empleado_id=$3`,
        [domBrilo, ahora, empId]
      );
      console.log(`  ~ DOM   ${empId} (${codigo}): "${domBrilo}"`);
      dpMap[empId].domicilio = domBrilo;
      domActualiz++;
    }

    // ── Dirección (si no tiene ninguna) ───────────────────────────────────────
    if (!tieneDir.has(empId) && (emp.empDireccion?.trim() || domBrilo)) {
      const calle  = (emp.empDireccion || '').trim() || domBrilo;
      const muni   = emp.resMuni || '';
      const depto  = emp.resDpto || '';
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_direcciones
           (empleado_id, tipo, direccion, municipio, departamento_geo, es_principal, created_at, updated_at)
         VALUES ($1, 'residencia', $2, $3, $4, true, $5, $5)`,
        [empId, calle, muni, depto, ahora]
      );
      console.log(`  + DIR   ${empId} (${codigo}): "${calle}", ${muni}, ${depto}`);
      tieneDir.add(empId);
      dirInserts++;
    }
  }

  await brilo.close();
  await pgRrhh.end();
  await pgMain.end();

  console.log(`\n=== Resumen ===`);
  console.log(`Documentos insertados      : ${docs}`);
  console.log(`DUI lugar/fecha actualizados: ${duiActualiz}`);
  console.log(`Profesiones actualizadas    : ${profActualiz}`);
  console.log(`Domicilios actualizados     : ${domActualiz}`);
  console.log(`Direcciones creadas         : ${dirInserts}`);
  console.log(`Sin match en nuestra BD     : ${sinMatch}`);
  if (DRY_RUN) console.log('\n[DRY RUN — no se escribió nada]');
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
