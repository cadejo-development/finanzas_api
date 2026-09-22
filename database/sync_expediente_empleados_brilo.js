/**
 * Sync completo de expediente: Brilo → RDS (core_db + rrhh_db)
 *
 * Cubre por cada empleado activo en Brilo que tiene match en nuestra BD:
 *   DATOS PERSONALES (expediente_datos_personales):
 *     - fecha_nacimiento, genero, estado_civil, grupo_sanguineo,
 *       lugar_nacimiento, profesion, domicilio
 *   FECHA INGRESO (core_db.empleados.fecha_ingreso si está vacía)
 *   DOCUMENTOS (expediente_documentos, solo si no existen):
 *     - DUI (numero), ISSS, AFP, NIT
 *   DOCUMENTOS — ACTUALIZACIÓN DE CAMPOS VACÍOS:
 *     - DUI: lugar y fecha de emisión si están vacíos
 *   DIRECCIÓN (expediente_direcciones, solo si el empleado no tiene ninguna)
 *   CONTACTOS (expediente_contactos, solo si el empleado no tiene ninguno):
 *     - Celular, Teléfono de casa, Contacto de emergencia
 *
 * Uso:
 *   node database/sync_expediente_empleados_brilo.js [--dry-run]
 *
 * --dry-run   : solo reporta cambios sin escribir nada
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
  options:  { encrypt: false, trustServerCertificate: true, connectTimeout: 60000, requestTimeout: 60000 },
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

// Mappings Brilo → nuestro formato
const AFP_NAMES = { 4: 'AFP CRECER', 5: 'AFP CONFIA', 6: 'IPSFA', 9: 'ISSS-IVM' };

const GENERO_MAP = { M: 'masculino', F: 'femenino' };

const ESTADO_CIVIL_MAP = {
  0: 'soltero',
  1: 'casado',
  2: 'divorciado',
  3: 'viudo',
  5: 'acompañado',
};

function toDate(val) {
  if (!val) return null;
  try { return new Date(val).toISOString().split('T')[0]; }
  catch { return null; }
}

function clean(s) {
  return (s || '').trim() || null;
}

async function main() {
  console.log(`=== Sync completo expediente Brilo → RDS ${DRY_RUN ? '[DRY RUN]' : '[PRODUCCIÓN]'} ===\n`);

  const brilo  = await sql.connect(briloConfig);
  const pgRrhh = new Client(pgRrhhConfig);
  const pgCore = new Client(pgCoreConfig);
  await pgRrhh.connect();
  await pgCore.connect();

  // ── 1. Empleados activos de Brilo ────────────────────────────────────────
  const { recordset: briloEmps } = await brilo.request().query(`
    SELECT
      e.empCodigo, e.empActivo,
      e.empFechaNacimiento, e.empSexo, e.empEstadoCivil, e.empTipoSangre,
      e.empLugarNacimiento, e.empProfesionDUI, e.empFechaIngreso,
      e.empISSS, e.empNumIPR, e.empNIT, e.empDUI, e.empDuiFechaExpedicion,
      e.iprId, ipr.iprNombreAbr,
      e.empDireccion, e.empCelular, e.empTelCasa,
      e.empNomAvisarA, e.empTelAvisarA,
      e.muniIdDuiExpedicion,
      mExp.muniNombre  AS duiMuni,
      deExp.dptoNombre AS duiDpto,
      e.muniIdResidencia,
      mRes.muniNombre  AS resMuni,
      deRes.dptoNombre AS resDpto
    FROM Empleados e
    LEFT JOIN InstPrevisional ipr   ON ipr.iprId   = e.iprId
    LEFT JOIN MuniCondados    mExp  ON mExp.muniId  = e.muniIdDuiExpedicion
    LEFT JOIN DeptosEstados   deExp ON deExp.dptoId = mExp.dptoId
    LEFT JOIN MuniCondados    mRes  ON mRes.muniId  = e.muniIdResidencia
    LEFT JOIN DeptosEstados   deRes ON deRes.dptoId = mRes.dptoId
    WHERE e.empActivo = 1
  `);
  console.log(`Empleados activos en Brilo : ${briloEmps.length}`);

  // ── 2. Nuestro mapa código → id ──────────────────────────────────────────
  const { rows: nuestrosEmps } = await pgCore.query(
    `SELECT id, codigo, fecha_ingreso FROM empleados WHERE activo = true AND codigo IS NOT NULL`
  );
  const codigoToEmp = {};
  nuestrosEmps.forEach(e => { codigoToEmp[String(e.codigo).trim()] = e; });

  // ── 3. Cargar estado actual en rrhh_db ───────────────────────────────────
  const [{ rows: docsRows }, { rows: dpRows }, { rows: dirRows }, { rows: ctRows }] =
    await Promise.all([
      pgRrhh.query(`SELECT id, empleado_id, tipo, numero, lugar_exp_texto, fecha_emision
                    FROM expediente_documentos`),
      pgRrhh.query(`SELECT empleado_id, fecha_nacimiento, genero, estado_civil,
                           grupo_sanguineo, lugar_nacimiento, profesion, domicilio
                    FROM expediente_datos_personales`),
      pgRrhh.query(`SELECT DISTINCT empleado_id FROM expediente_direcciones`),
      pgRrhh.query(`SELECT DISTINCT empleado_id FROM expediente_contactos`),
    ]);

  const docSet   = new Set(docsRows.map(d => `${d.empleado_id}:${d.tipo}`));
  const duiMap   = {};  // empId → { id, numero, lugar_exp_texto, fecha_emision }
  docsRows.filter(d => d.tipo === 'dui').forEach(d => {
    duiMap[d.empleado_id] = { id: d.id, numero: d.numero, lugar: d.lugar_exp_texto, fecha: d.fecha_emision };
  });

  const dpMap    = {};  // empId → { fecha_nacimiento, genero, ... }
  dpRows.forEach(d => { dpMap[d.empleado_id] = d; });

  const tieneDir = new Set(dirRows.map(d => d.empleado_id));
  const tieneCt  = new Set(ctRows.map(d => d.empleado_id));

  // ── 4. Contadores ────────────────────────────────────────────────────────
  let stats = {
    sinMatch: 0,
    dpActualiz: 0, fechaIngresoActualiz: 0,
    docs: 0, duiActualiz: 0,
    dirs: 0, contactos: 0,
  };

  const ahora = new Date().toISOString();

  // ── 5. Bucle principal ───────────────────────────────────────────────────
  for (const emp of briloEmps) {
    const codigo = String(emp.empCodigo || '').trim();
    const nuestro = codigoToEmp[codigo];
    if (!nuestro) { stats.sinMatch++; continue; }
    const empId = nuestro.id;

    // ── DATOS PERSONALES ──────────────────────────────────────────────────
    const dp = dpMap[empId] || {};
    const dpUpdates   = {};
    const dpLog       = [];

    const fechaNac = toDate(emp.empFechaNacimiento);
    if (fechaNac && !dp.fecha_nacimiento) {
      dpUpdates.fecha_nacimiento = fechaNac;
      dpLog.push(`fecha_nac=${fechaNac}`);
    }

    const genero = GENERO_MAP[clean(emp.empSexo)] || null;
    if (genero && !dp.genero) {
      dpUpdates.genero = genero;
      dpLog.push(`genero=${genero}`);
    }

    const ecVal = emp.empEstadoCivil != null ? ESTADO_CIVIL_MAP[emp.empEstadoCivil] ?? null : null;
    if (ecVal && !dp.estado_civil) {
      dpUpdates.estado_civil = ecVal;
      dpLog.push(`estado_civil=${ecVal}`);
    }

    const sangre = clean(emp.empTipoSangre);
    if (sangre && !dp.grupo_sanguineo) {
      dpUpdates.grupo_sanguineo = sangre;
      dpLog.push(`sangre=${sangre}`);
    }

    const lugarNac = clean(emp.empLugarNacimiento);
    if (lugarNac && !dp.lugar_nacimiento) {
      dpUpdates.lugar_nacimiento = lugarNac;
      dpLog.push(`lugar_nac=${lugarNac}`);
    }

    const prof = clean(emp.empProfesionDUI);
    if (prof && !dp.profesion) {
      dpUpdates.profesion = prof;
      dpLog.push(`profesion=${prof}`);
    }

    const domBrilo = [clean(emp.resMuni), clean(emp.resDpto)].filter(Boolean).join(', ');
    if (domBrilo && !dp.domicilio) {
      dpUpdates.domicilio = domBrilo;
      dpLog.push(`domicilio=${domBrilo}`);
    }

    if (Object.keys(dpUpdates).length > 0) {
      console.log(`  ~ DP    ${empId} (${codigo}): ${dpLog.join(' | ')}`);
      if (!DRY_RUN) {
        const sets  = Object.keys(dpUpdates).map((k, i) => `${k}=$${i + 2}`).join(', ');
        const vals  = Object.values(dpUpdates);
        if (dp.empleado_id) {
          // UPDATE
          await pgRrhh.query(
            `UPDATE expediente_datos_personales SET ${sets}, updated_at=$${vals.length + 2} WHERE empleado_id=$1`,
            [empId, ...vals, ahora]
          );
        } else {
          // INSERT (el registro de datos personales no existe todavía)
          const cols = ['empleado_id', ...Object.keys(dpUpdates), 'created_at', 'updated_at'];
          const phs  = cols.map((_, i) => `$${i + 1}`).join(', ');
          await pgRrhh.query(
            `INSERT INTO expediente_datos_personales (${cols.join(', ')}) VALUES (${phs})
             ON CONFLICT (empleado_id) DO UPDATE SET ${sets}, updated_at=$${cols.length}`,
            [empId, ...vals, ahora, ahora]
          );
        }
      }
      stats.dpActualiz++;
    }

    // ── FECHA INGRESO (core_db.empleados) ─────────────────────────────────
    const fechaIngBrilo = toDate(emp.empFechaIngreso);
    if (fechaIngBrilo && !nuestro.fecha_ingreso) {
      console.log(`  ~ ING   ${empId} (${codigo}): fecha_ingreso=${fechaIngBrilo}`);
      if (!DRY_RUN) {
        await pgCore.query(
          `UPDATE empleados SET fecha_ingreso=$1, updated_at=$2 WHERE id=$3`,
          [fechaIngBrilo, ahora, empId]
        );
      }
      stats.fechaIngresoActualiz++;
    }

    // ── DOCUMENTOS: ISSS ──────────────────────────────────────────────────
    const isss = clean(emp.empISSS);
    if (isss && !docSet.has(`${empId}:isss`)) {
      console.log(`  + ISSS  ${empId} (${codigo}): ${isss}`);
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_documentos (empleado_id,tipo,numero,notas,created_at,updated_at)
         VALUES ($1,'isss',$2,'sync:brilo',$3,$3)`,
        [empId, isss, ahora]
      );
      docSet.add(`${empId}:isss`);
      stats.docs++;
    }

    // ── DOCUMENTOS: AFP ───────────────────────────────────────────────────
    const afpNum = clean(emp.empNumIPR);
    if (afpNum && !docSet.has(`${empId}:afp`)) {
      const afpNombre = clean(emp.iprNombreAbr) || AFP_NAMES[emp.iprId] || 'AFP';
      console.log(`  + AFP   ${empId} (${codigo}): ${afpNum} (${afpNombre})`);
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_documentos (empleado_id,tipo,numero,entidad_emisora,notas,created_at,updated_at)
         VALUES ($1,'afp',$2,$3,'sync:brilo',$4,$4)`,
        [empId, afpNum, afpNombre, ahora]
      );
      docSet.add(`${empId}:afp`);
      stats.docs++;
    }

    // ── DOCUMENTOS: NIT ───────────────────────────────────────────────────
    const nit = clean(emp.empNIT);
    if (nit && !docSet.has(`${empId}:nit`)) {
      console.log(`  + NIT   ${empId} (${codigo}): ${nit}`);
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_documentos (empleado_id,tipo,numero,notas,created_at,updated_at)
         VALUES ($1,'nit',$2,'sync:brilo',$3,$3)`,
        [empId, nit, ahora]
      );
      docSet.add(`${empId}:nit`);
      stats.docs++;
    }

    // ── DOCUMENTOS: DUI (crear número si no existe) ───────────────────────
    const duiNum = clean(emp.empDUI);
    if (duiNum && !docSet.has(`${empId}:dui`)) {
      const lugarBrilo = [clean(emp.duiMuni), clean(emp.duiDpto)].filter(Boolean).join(', ');
      const fechaDui   = toDate(emp.empDuiFechaExpedicion);
      console.log(`  + DUI   ${empId} (${codigo}): ${duiNum}`);
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_documentos
           (empleado_id,tipo,numero,lugar_exp_texto,fecha_emision,notas,created_at,updated_at)
         VALUES ($1,'dui',$2,$3,$4,'sync:brilo',$5,$5)`,
        [empId, duiNum, lugarBrilo || null, fechaDui, ahora]
      );
      docSet.add(`${empId}:dui`);
      stats.docs++;
    }

    // ── DUI: actualizar lugar/fecha si el registro ya existe ──────────────
    if (duiMap[empId]) {
      const dui        = duiMap[empId];
      const lugarBrilo = [clean(emp.duiMuni), clean(emp.duiDpto)].filter(Boolean).join(', ');
      const fechaDui   = toDate(emp.empDuiFechaExpedicion);
      const needsLugar = lugarBrilo && !dui.lugar?.trim();
      const needsFecha = fechaDui && !dui.fecha;
      if (needsLugar || needsFecha) {
        console.log(`  ~ DUI   ${empId} (${codigo}): lugar="${lugarBrilo}" fecha=${fechaDui}`);
        if (!DRY_RUN) await pgRrhh.query(
          `UPDATE expediente_documentos
           SET lugar_exp_texto = COALESCE(NULLIF(lugar_exp_texto,''), $1),
               fecha_emision   = COALESCE(fecha_emision, $2),
               updated_at      = $3
           WHERE id = $4`,
          [lugarBrilo || null, fechaDui || null, ahora, dui.id]
        );
        stats.duiActualiz++;
      }
    }

    // ── DIRECCIÓN (si no tiene ninguna) ───────────────────────────────────
    if (!tieneDir.has(empId)) {
      const calle = clean(emp.empDireccion) || domBrilo;
      const muni  = clean(emp.resMuni);
      const depto = clean(emp.resDpto);
      if (calle || muni) {
        console.log(`  + DIR   ${empId} (${codigo}): "${calle}", ${muni}, ${depto}`);
        if (!DRY_RUN) await pgRrhh.query(
          `INSERT INTO expediente_direcciones
             (empleado_id,tipo,direccion,municipio,departamento_geo,es_principal,created_at,updated_at)
           VALUES ($1,'residencia',$2,$3,$4,true,$5,$5)`,
          [empId, calle, muni, depto, ahora]
        );
        tieneDir.add(empId);
        stats.dirs++;
      }
    }

    // ── CONTACTOS (si no tiene ninguno) ───────────────────────────────────
    if (!tieneCt.has(empId)) {
      const celular = clean(emp.empCelular);
      const telCasa = clean(emp.empTelCasa);
      const nomEmerg = clean(emp.empNomAvisarA);
      const telEmerg = clean(emp.empTelAvisarA);

      const contactosAInsertar = [];
      if (celular)  contactosAInsertar.push({ tipo: 'celular',       valor: celular, emergencia: false, nombre: null });
      if (telCasa)  contactosAInsertar.push({ tipo: 'telefono_casa', valor: telCasa, emergencia: false, nombre: null });
      if (telEmerg || nomEmerg)
        contactosAInsertar.push({ tipo: 'celular', valor: telEmerg || '—', emergencia: true, nombre: nomEmerg });

      for (const ct of contactosAInsertar) {
        console.log(`  + CT    ${empId} (${codigo}): ${ct.tipo} ${ct.valor}${ct.nombre ? ` (${ct.nombre})` : ''}`);
        if (!DRY_RUN) await pgRrhh.query(
          `INSERT INTO expediente_contactos
             (empleado_id,tipo,valor,nombre_contacto,es_emergencia,orden,created_at,updated_at)
           VALUES ($1,$2,$3,$4,$5,$6,$7,$7)`,
          [empId, ct.tipo, ct.valor, ct.nombre, ct.emergencia,
           contactosAInsertar.indexOf(ct) + 1, ahora]
        );
        stats.contactos++;
      }
      if (contactosAInsertar.length) tieneCt.add(empId);
    }
  }

  await brilo.close();
  await pgRrhh.end();
  await pgCore.end();

  console.log(`\n=== Resumen ${DRY_RUN ? '(DRY RUN)' : ''} ===`);
  console.log(`Sin match código              : ${stats.sinMatch}`);
  console.log(`Datos personales actualizados : ${stats.dpActualiz}`);
  console.log(`Fechas de ingreso actualizadas: ${stats.fechaIngresoActualiz}`);
  console.log(`Documentos insertados         : ${stats.docs}`);
  console.log(`DUI lugar/fecha actualizados  : ${stats.duiActualiz}`);
  console.log(`Direcciones creadas           : ${stats.dirs}`);
  console.log(`Contactos insertados          : ${stats.contactos}`);
  if (DRY_RUN) console.log('\n[DRY RUN — no se escribió nada en la BD]');
}

main().catch(e => { console.error('ERROR:', e.message, e.stack); process.exit(1); });
