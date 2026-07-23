require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

/**
 * sync_expediente_desde_sqlserver.js
 *
 * SQL Server (olcomun) → RDS rrhh_db
 *
 * Sincroniza al expediente la data personal que viene de SQL Server:
 *   expediente_datos_personales:
 *     - genero, fecha_nacimiento, estado_civil, grupo_sanguineo, lugar_nacimiento
 *   expediente_contactos:
 *     - celular, teléfono casa, contacto de emergencia
 *
 * Reglas:
 *   - Si el campo ya tiene valor en rrhh_db → NO se toca.
 *   - Si el registro no existe → se inserta con los datos disponibles.
 *   - Si el registro existe y el campo está NULL → se actualiza.
 *
 * Uso:
 *   node sync_expediente_desde_sqlserver.js             (ejecuta)
 *   node sync_expediente_desde_sqlserver.js --dry-run   (sin cambios)
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const MSSQL_CFG = {
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_PASSWORD_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

const PG_CORE = {
  host: process.env.DB_HOST, port: 5432,
  database: 'core_db', user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
};

const PG_RRHH = {
  host: process.env.DB_HOST, port: 5432,
  database: 'rrhh_db', user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
};

const DRY_RUN = process.argv.includes('--dry-run');
const NOW     = new Date().toISOString();
const AUD     = 'sync_expediente_desde_sqlserver.js';
const clean   = (v, max = 150) => !v ? null : String(v).trim().replace(/\s+/g, ' ').slice(0, max) || null;
const toDate  = v => v ? new Date(v).toISOString().split('T')[0] : null;
const ts      = () => new Date().toTimeString().slice(0, 8);
const log     = s  => console.log(`[${ts()}] ${s}`);

const SEXO_MAP = { M: 'masculino', F: 'femenino' };

// Abreviaciones de departamentos que aparecen en SQL Server
const DEPTO_ALIAS = {
  'SS': 'SAN SALVADOR', 'S.S.': 'SAN SALVADOR', 'SAN SALV': 'SAN SALVADOR',
  'USULL': 'USULUTAN', 'USULUTAN': 'USULUTAN', 'USULUTÁN': 'USULUTAN',
  'LA LIB': 'LA LIBERTAD', 'LALIBERTAD': 'LA LIBERTAD',
  'S.ANA': 'SANTA ANA', 'STA ANA': 'SANTA ANA',
  'CHAL': 'CHALATENANGO', 'CHALA': 'CHALATENANGO',
  'CUS': 'CUSCATLAN', 'CUSC': 'CUSCATLAN',
  'SONSONATE': 'SONSONATE',
  'S.MIGUEL': 'SAN MIGUEL', 'SMiguel': 'SAN MIGUEL',
  'MORAZAN': 'MORAZAN', 'MORAZÁN': 'MORAZAN',
  'LA PAZ': 'LA PAZ',
  'SAN VIC': 'SAN VICENTE', 'S.VICENTE': 'SAN VICENTE',
  'CABANAS': 'CABANAS', 'CABAÑAS': 'CABANAS',
  'LA UNION': 'LA UNION', 'LA UNIÓN': 'LA UNION',
};

function norm(s) {
  return (s || '').toUpperCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^A-Z0-9\s]/g, ' ').replace(/\s+/g, ' ').trim();
}

function parsearMunicipio(texto, municipios) {
  if (!texto) return null;
  const t = norm(texto);

  // Intentar separar "MUNICIPIO, DEPARTAMENTO"
  const coma = t.indexOf(',');
  const parteMuni  = coma > -1 ? t.slice(0, coma).trim() : t;
  const parteDepto = coma > -1 ? t.slice(coma + 1).trim() : '';

  // Normalizar abreviación de departamento si aplica
  const deptoNorm = DEPTO_ALIAS[parteDepto] ?? parteDepto;

  // 1. Buscar municipios cuyo nombre normalizado coincida exactamente
  const candidatos = municipios.filter(m => norm(m.municipio) === parteMuni);

  if (candidatos.length === 1) return candidatos[0];

  // 2. Si hay varios con ese nombre (e.g. "San Lorenzo" en varios deptos), desambiguar por depto
  if (candidatos.length > 1 && deptoNorm) {
    const porDepto = candidatos.find(m => norm(m.departamento).includes(deptoNorm.slice(0, 5)));
    if (porDepto) return porDepto;
  }

  // 3. Si no hubo coincidencia exacta, intentar también con el texto completo (sin coma)
  if (coma === -1) {
    const full = municipios.find(m => norm(m.municipio) === t);
    if (full) return full;
  }

  // 4. Sin match confiable
  return null;
}

const ESTADO_CIVIL_MAP = {
  0: 'soltero',
  1: 'casado',
  2: 'divorciado',
  3: 'viudo',
  5: 'acompañado',
};

// ─────────────────────────────────────────────────────────────────────────────
async function run() {
  if (DRY_RUN) log('=== MODO DRY-RUN: sin cambios en BD ===\n');

  log('Conectando a SQL Server...');
  const mssqlPool = await sql.connect(MSSQL_CFG);
  log('SQL Server OK.');

  log('Conectando a PostgreSQL...');
  const pgCore = new Pool(PG_CORE);
  const pgRrhh = new Pool(PG_RRHH);
  await Promise.all([pgCore.query('SELECT 1'), pgRrhh.query('SELECT 1')]);
  log('PostgreSQL OK.\n');

  try {
    // ── 1. Leer empleados activos desde SQL Server ────────────────────────────
    log('Leyendo empleados desde SQL Server...');
    const { recordset } = await mssqlPool.request().query(`
      SELECT
        RTRIM(e.empCodigo)          AS codigo,
        RTRIM(e.empSexo)            AS sexo,
        e.empFechaNacimiento        AS fecha_nacimiento,
        e.empEstadoCivil            AS estado_civil,
        RTRIM(e.empTipoSangre)      AS tipo_sangre,
        RTRIM(e.empLugarNacimiento) AS lugar_nacimiento,
        RTRIM(e.empCelular)         AS celular,
        RTRIM(e.empTelCasa)         AS tel_casa,
        RTRIM(e.empNomAvisarA)      AS nom_avisar,
        RTRIM(e.empTelAvisarA)      AS tel_avisar,
        RTRIM(e.empDUI)             AS dui,
        RTRIM(e.empTipoDocumento)   AS tipo_doc,
        RTRIM(e.empNumeroDocumento) AS num_doc
      FROM olComun.dbo.Empleados e WITH (NOLOCK)
      WHERE e.empActivo = 1
        AND e.empCodigo IS NOT NULL
    `);
    log(`SQL Server: ${recordset.length} empleados activos\n`);

    // ── 2. Mapear código → empleado_id desde core_db ──────────────────────────
    log('Cargando IDs de empleados desde core_db...');
    const { rows: empRows } = await pgCore.query('SELECT id, codigo FROM empleados WHERE activo = true');
    const idPorCodigo = Object.fromEntries(empRows.map(r => [r.codigo, r.id]));
    log(`core_db: ${empRows.length} empleados activos\n`);

    // ── 3. Cargar municipios de core_db para el parseo de lugar_nacimiento ───
    log('Cargando municipios desde core_db...');
    const { rows: munRows } = await pgCore.query(
      'SELECT m.id, m.nombre AS municipio, d.nombre AS departamento FROM geo_municipios m JOIN geo_departamentos d ON d.id = m.departamento_id'
    );
    log(`core_db: ${munRows.length} municipios cargados\n`);

    // ── 4. Leer estado actual de expediente_datos_personales ─────────────────
    log('Cargando expediente_datos_personales...');
    const { rows: expRows } = await pgRrhh.query(
      'SELECT empleado_id, genero, fecha_nacimiento, estado_civil, grupo_sanguineo, lugar_nacimiento, nacimiento_municipio_id FROM expediente_datos_personales'
    );
    const expPorId = Object.fromEntries(expRows.map(r => [r.empleado_id, r]));
    log(`rrhh_db: ${expRows.length} registros existentes en expediente_datos_personales\n`);

    // ── 4. Leer todos los contactos existentes (valor + etiqueta) ────────────
    log('Cargando expediente_contactos...');
    const { rows: ctRows } = await pgRrhh.query(
      `SELECT empleado_id, etiqueta, valor FROM expediente_contactos WHERE tipo = 'telefono'`
    );
    // Normalizar teléfono: quitar espacios, guiones, paréntesis → solo dígitos
    const normTel = v => (v || '').replace(/[\s\-().+]/g, '');
    // Doble check: por etiqueta Y por valor normalizado
    const ctPorEtiqueta = new Set(ctRows.filter(r => ['celular','casa','emergencia'].includes(r.etiqueta)).map(r => `${r.empleado_id}:${r.etiqueta}`));
    const ctPorValor    = new Set(ctRows.map(r => `${r.empleado_id}:${normTel(r.valor)}`).filter(k => k.endsWith(':') === false && !k.endsWith(':')));
    log(`rrhh_db: ${ctRows.length} contactos de teléfono existentes\n`);

    const yaExisteContacto = (empId, etiqueta, valor) => {
      if (ctPorEtiqueta.has(`${empId}:${etiqueta}`)) return true;
      const vNorm = normTel(valor);
      if (vNorm && ctPorValor.has(`${empId}:${vNorm}`)) return true;
      return false;
    };

    // ── 5. Calcular inserciones/actualizaciones para datos personales ─────────
    const dpInsertar   = [];
    const dpActualizar = [];
    const ctInsertar   = [];
    const docInsertar  = [];
    let sinMatch = 0;

    // ── 4b. Leer documentos ya existentes en rrhh_db ────────────────────────
    log('Cargando expediente_documentos...');
    const { rows: docsRows } = await pgRrhh.query(
      `SELECT empleado_id, tipo FROM expediente_documentos WHERE tipo IN ('dui','pasaporte','carnet_residente')`
    );
    const docExistente = new Set(docsRows.map(r => `${r.empleado_id}:${r.tipo}`));
    log(`rrhh_db: ${docsRows.length} documentos existentes\n`);

    for (const row of recordset) {
      const empId = idPorCodigo[row.codigo];
      if (!empId) { sinMatch++; continue; }

      // ── Datos personales ──────────────────────────────────────────────────
      const generoNuevo    = row.sexo         ? (SEXO_MAP[row.sexo] ?? null)                    : null;
      const fechaNueva     = row.fecha_nacimiento ? toDate(row.fecha_nacimiento)                 : null;
      const estadoCivil    = row.estado_civil != null ? (ESTADO_CIVIL_MAP[row.estado_civil] ?? null) : null;
      const grupoSanguineo = clean(row.tipo_sangre, 10);

      // Parsear lugar_nacimiento inteligentemente:
      //   Si match → municipio_id + lugar_nacimiento = null (no es un complemento)
      //   Si no match → guardar el texto en lugar_nacimiento como texto libre
      const munMatch   = parsearMunicipio(row.lugar_nacimiento, munRows);
      const munId      = munMatch ? munMatch.id : null;
      const lugarTexto = munMatch ? null : clean(row.lugar_nacimiento, 150);

      const exp = expPorId[empId];

      if (!exp) {
        if (generoNuevo || fechaNueva || estadoCivil || grupoSanguineo || munId || lugarTexto) {
          dpInsertar.push({ empleado_id: empId, genero: generoNuevo, fecha_nacimiento: fechaNueva, estado_civil: estadoCivil, grupo_sanguineo: grupoSanguineo, nacimiento_municipio_id: munId, lugar_nacimiento: lugarTexto });
        }
      } else {
        const campos = {};
        if (!exp.genero                  && generoNuevo)    campos.genero                  = generoNuevo;
        if (!exp.fecha_nacimiento        && fechaNueva)      campos.fecha_nacimiento        = fechaNueva;
        if (!exp.estado_civil            && estadoCivil)     campos.estado_civil            = estadoCivil;
        if (!exp.grupo_sanguineo         && grupoSanguineo)  campos.grupo_sanguineo         = grupoSanguineo;
        if (!exp.nacimiento_municipio_id && munId)           campos.nacimiento_municipio_id = munId;
        // Si ya teníamos texto en lugar_nacimiento pero era el texto del municipio (que acabamos de mapear)
        // lo limpiamos; si el municipio ya está mapeado y hay texto libre lo dejamos
        if (!exp.nacimiento_municipio_id && munId && exp.lugar_nacimiento) {
          campos.lugar_nacimiento = null; // el texto fue el municipio, ya no hace falta
        }
        if (!exp.nacimiento_municipio_id && !munId && !exp.lugar_nacimiento && lugarTexto) {
          campos.lugar_nacimiento = lugarTexto;
        }
        if (Object.keys(campos).length) dpActualizar.push({ empleado_id: empId, ...campos });
      }

      // ── Documento de identidad ───────────────────────────────────────────
      const duiVal  = clean(row.dui, 20);
      const docTipo = clean(row.tipo_doc, 30);
      const docNum  = clean(row.num_doc, 40);
      // Prioridad: DUI propio → otro tipo de documento
      const docFinal = duiVal || (docTipo && docNum ? docNum : null);
      const tipoFinal = duiVal ? 'dui' : (docTipo ? docTipo.toLowerCase().replace(/\s+/g, '_') : null);
      if (docFinal && tipoFinal && !docExistente.has(`${empId}:${tipoFinal}`)) {
        docInsertar.push({ empleado_id: empId, tipo: tipoFinal, numero: docFinal });
        docExistente.add(`${empId}:${tipoFinal}`);
      }

      // ── Contactos ─────────────────────────────────────────────────────────
      const celular  = clean(row.celular, 30);
      const telCasa  = clean(row.tel_casa, 30);
      const nomAvisa = clean(row.nom_avisar, 100);
      const telAvisa = clean(row.tel_avisar, 30);

      if (celular && !yaExisteContacto(empId, 'celular', celular)) {
        ctInsertar.push({ empleado_id: empId, tipo: 'telefono', etiqueta: 'celular', valor: celular, es_emergencia: false, nombre_contacto: null });
      }
      if (telCasa && !yaExisteContacto(empId, 'casa', telCasa)) {
        ctInsertar.push({ empleado_id: empId, tipo: 'telefono', etiqueta: 'casa', valor: telCasa, es_emergencia: false, nombre_contacto: null });
      }
      // Emergencia: solo si hay teléfono y no duplica
      if (telAvisa && !yaExisteContacto(empId, 'emergencia', telAvisa)) {
        ctInsertar.push({ empleado_id: empId, tipo: 'telefono', etiqueta: 'emergencia', valor: telAvisa, es_emergencia: true, nombre_contacto: nomAvisa });
      }
    }

    // ── 5b. Pre-calcular experiencia laboral Cadejo (antes del dry-run check) ──
    const { rows: coreEmps } = await pgCore.query(`
      SELECT e.id, e.fecha_ingreso, c.nombre AS cargo
      FROM empleados e
      LEFT JOIN cargos c ON c.id = e.cargo_id
      WHERE e.activo = true AND e.fecha_ingreso IS NOT NULL
    `);
    let existCadejo = [];
    try {
      const { rows } = await pgRrhh.query(`
        SELECT id, empleado_id, cargo, fecha_inicio, es_cadejo
        FROM expediente_experiencia_laboral
        WHERE es_cadejo = true OR empresa ILIKE '%cadejo brewing%'
      `);
      existCadejo = rows;
    } catch {
      const { rows } = await pgRrhh.query(`
        SELECT id, empleado_id, cargo, fecha_inicio
        FROM expediente_experiencia_laboral
        WHERE empresa ILIKE '%cadejo brewing%'
      `);
      existCadejo = rows.map(r => ({ ...r, es_cadejo: true }));
    }
    const cadjoPorEmpId = Object.fromEntries(existCadejo.map(r => [r.empleado_id, r]));
    const expInsertar  = [];
    const expActualizar = [];
    for (const emp of coreEmps) {
      const cargo    = clean(emp.cargo, 150) ?? 'Colaborador';
      const fechaIng = toDate(emp.fecha_ingreso);
      const existing = cadjoPorEmpId[emp.id];
      if (!existing) {
        expInsertar.push({ empleado_id: emp.id, cargo, fecha_inicio: fechaIng });
      } else if (!existing.es_cadejo || existing.cargo !== cargo) {
        expActualizar.push({ id: existing.id, cargo });
      }
    }

    log('──────────────────────────────────────────────────────');
    log(`Datos personales INSERTAR (sin registro): ${dpInsertar.length}`);
    log(`Datos personales ACTUALIZAR (null→valor): ${dpActualizar.length}`);
    log(`Documentos       INSERTAR (nuevos):       ${docInsertar.length}`);
    log(`Contactos        INSERTAR (nuevos):       ${ctInsertar.length}`);
    log(`Experiencia Cadejo INSERTAR:              ${expInsertar.length}`);
    log(`Experiencia Cadejo ACTUALIZAR:            ${expActualizar.length}`);
    log(`Sin match en core_db:                     ${sinMatch}`);
    log('──────────────────────────────────────────────────────\n');

    if (DRY_RUN) {
      log('(dry-run) Sin cambios. Quita --dry-run para aplicar.');
      return;
    }

    // ── 6. Insertar nuevos registros de datos personales ────────────────────
    let dpInsOk = 0;
    for (const r of dpInsertar) {
      await pgRrhh.query(
        `INSERT INTO expediente_datos_personales
           (empleado_id, genero, fecha_nacimiento, estado_civil, grupo_sanguineo, nacimiento_municipio_id, lugar_nacimiento, aud_usuario, created_at, updated_at)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$9)
         ON CONFLICT (empleado_id) DO NOTHING`,
        [r.empleado_id, r.genero, r.fecha_nacimiento, r.estado_civil, r.grupo_sanguineo, r.nacimiento_municipio_id, r.lugar_nacimiento, AUD, NOW]
      );
      dpInsOk++;
      process.stdout.write(`\r  Datos personales insertados: ${dpInsOk}/${dpInsertar.length} `);
    }
    if (dpInsertar.length) console.log();

    // ── 7. Actualizar campos NULL en datos personales existentes ─────────────
    let dpUpdOk = 0;
    for (const r of dpActualizar) {
      const sets   = [];
      const params = [];
      const campos = ['genero','fecha_nacimiento','estado_civil','grupo_sanguineo','nacimiento_municipio_id'];
      for (const c of campos) {
        if (r[c] != null) { params.push(r[c]); sets.push(`${c} = $${params.length}`); }
      }
      // lugar_nacimiento puede enviarse como null explícito para limpiarlo
      if ('lugar_nacimiento' in r) {
        params.push(r.lugar_nacimiento);
        sets.push(`lugar_nacimiento = $${params.length}`);
      }
      params.push(AUD);  sets.push(`aud_usuario = $${params.length}`);
      params.push(NOW);  sets.push(`updated_at  = $${params.length}`);
      params.push(r.empleado_id);

      await pgRrhh.query(
        `UPDATE expediente_datos_personales SET ${sets.join(', ')} WHERE empleado_id = $${params.length}`,
        params
      );
      dpUpdOk++;
      process.stdout.write(`\r  Datos personales actualizados: ${dpUpdOk}/${dpActualizar.length} `);
    }
    if (dpActualizar.length) console.log();

    // ── 8. Insertar documentos nuevos ────────────────────────────────────────
    let docInsOk = 0;
    for (const r of docInsertar) {
      await pgRrhh.query(
        `INSERT INTO expediente_documentos
           (empleado_id, tipo, numero, created_at, updated_at)
         VALUES ($1,$2,$3,$4,$4)
         ON CONFLICT DO NOTHING`,
        [r.empleado_id, r.tipo, r.numero, NOW]
      );
      docInsOk++;
      process.stdout.write(`\r  Documentos insertados: ${docInsOk}/${docInsertar.length} `);
    }
    if (docInsertar.length) console.log();

    // ── 8b. Insertar contactos nuevos ─────────────────────────────────────────
    let ctInsOk = 0;
    for (const r of ctInsertar) {
      await pgRrhh.query(
        `INSERT INTO expediente_contactos
           (empleado_id, tipo, etiqueta, valor, es_emergencia, nombre_contacto, created_at, updated_at)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$7)`,
        [r.empleado_id, r.tipo, r.etiqueta, r.valor, r.es_emergencia, r.nombre_contacto, NOW]
      );
      ctInsOk++;
      process.stdout.write(`\r  Contactos insertados: ${ctInsOk}/${ctInsertar.length} `);
    }
    if (ctInsertar.length) console.log();

    // ── 9. Aplicar experiencia laboral Cadejo ─────────────────────────────────
    // Asegurar columna es_cadejo (idempotente)
    await pgRrhh.query(`
      ALTER TABLE expediente_experiencia_laboral
        ADD COLUMN IF NOT EXISTS es_cadejo BOOLEAN NOT NULL DEFAULT false
    `);

    let expInsOk = 0;
    for (const r of expInsertar) {
      await pgRrhh.query(
        `INSERT INTO expediente_experiencia_laboral
           (empleado_id, empresa, cargo, fecha_inicio, es_actual, pais, es_cadejo, created_at, updated_at)
         VALUES ($1,'Cadejo Brewing Company',$2,$3,true,'El Salvador',true,$4,$4)`,
        [r.empleado_id, r.cargo, r.fecha_inicio, NOW]
      );
      expInsOk++;
      process.stdout.write(`\r  Experiencia Cadejo insertada: ${expInsOk}/${expInsertar.length} `);
    }
    if (expInsertar.length) console.log();

    let expUpdOk = 0;
    for (const r of expActualizar) {
      await pgRrhh.query(
        `UPDATE expediente_experiencia_laboral
            SET cargo = $1, es_cadejo = true, updated_at = $2
          WHERE id = $3`,
        [r.cargo, NOW, r.id]
      );
      expUpdOk++;
      process.stdout.write(`\r  Experiencia Cadejo actualizada: ${expUpdOk}/${expActualizar.length} `);
    }
    if (expActualizar.length) console.log();

    // ── 10. Resumen ───────────────────────────────────────────────────────────
    const { rows: res } = await pgRrhh.query(`
      SELECT
        COUNT(*) FILTER (WHERE genero IS NOT NULL)           AS con_genero,
        COUNT(*) FILTER (WHERE fecha_nacimiento IS NOT NULL) AS con_fecha_nac,
        COUNT(*) FILTER (WHERE estado_civil IS NOT NULL)     AS con_estado_civil,
        COUNT(*) FILTER (WHERE grupo_sanguineo IS NOT NULL)  AS con_grupo_sang
      FROM expediente_datos_personales
    `);
    log('\n==========================================');
    log('RESUMEN FINAL');
    log(`  Datos personales insertados:  ${dpInsOk}`);
    log(`  Datos personales actualizados:${dpUpdOk}`);
    log(`  Contactos insertados:         ${ctInsOk}`);
    log(`  Con género en exp.:           ${res[0].con_genero}`);
    log(`  Con fecha nacimiento:         ${res[0].con_fecha_nac}`);
    log(`  Con estado civil:             ${res[0].con_estado_civil}`);
    log(`  Con grupo sanguíneo:          ${res[0].con_grupo_sang}`);
    log(`  Experiencia Cadejo insertadas:${expInsertar.length}`);
    log(`  Experiencia Cadejo actualizadas:${expActualizar.length}`);
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
