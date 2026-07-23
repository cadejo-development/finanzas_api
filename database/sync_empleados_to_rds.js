/**
 * sync_empleados_to_rds.js
 *
 * SQL Server (olcomun) → RDS PostgreSQL (core_db + rrhh_db)
 *
 * Sincroniza en dos fases:
 *
 *  FASE 1 — Empleados (core_db)
 *    - Empleado YA existe en RDS  (match por codigo) → actualiza departamento, cargo, salario, etc.
 *    - Empleado NO existe en RDS  + activo en Brilo  → inserta completo
 *
 *  FASE 2 — Expediente completo (rrhh_db) — solo activos
 *    - expediente_datos_personales : fecha_nacimiento, genero, estado_civil, lugar_nacimiento,
 *                                    grupo_sanguineo, profesion, domicilio, nacionalidad
 *    - expediente_documentos       : ISSS, AFP, NIT, DUI (lugar+fecha expedición+vencimiento)
 *    - expediente_contactos        : celular, teléfono casa, contacto de emergencia
 *    - expediente_cuentas_banco    : cuenta bancaria
 *    - expediente_direcciones      : dirección de residencia
 *
 * Todas las escrituras de expediente son "fill-only": solo rellena campos vacíos;
 * nunca sobreescribe datos que el equipo RRHH ya haya ingresado manualmente.
 *
 * Uso:
 *   node database/sync_empleados_to_rds.js             (ejecuta ambas fases)
 *   node database/sync_empleados_to_rds.js --dry-run   (muestra conteos, no escribe)
 *   node database/sync_empleados_to_rds.js --skip-expediente  (solo fase 1)
 */

require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const sql      = require('mssql');
const { Pool } = require('pg');

function fmtDate(d) {
  if (!d) return null;
  if (d instanceof Date) return d.toISOString().split('T')[0];
  return String(d).split('T')[0];
}

// ── SQL Server ────────────────────────────────────────────────────────────────
const MSSQL_CFG = {
  user:     process.env.DB_USERNAME_ORIGEN,
  password: process.env.DB_PASSWORD_ORIGEN,
  server:   process.env.DB_HOST_ORIGEN,
  port:     Number(process.env.DB_PORT_ORIGEN) || 2033,
  database: process.env.DB_DATABASE_ORIGEN,
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
  requestTimeout: 180000,
};

// ── RDS PostgreSQL core_db ────────────────────────────────────────────────────
const PG_CFG = {
  host:     process.env.DB_HOST,
  port:     Number(process.env.DB_PORT) || 5432,
  database: process.env.DB_DATABASE,
  user:     process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
  connectionTimeoutMillis: 30000,
  idleTimeoutMillis: 600000,
  query_timeout: 60000,
  keepAlive: true,
  keepAliveInitialDelayMillis: 10000,
};

// ── RDS PostgreSQL rrhh_db ────────────────────────────────────────────────────
const PG_RRHH_CFG = { ...PG_CFG, database: 'rrhh_db' };

// ── Catálogos Brilo ───────────────────────────────────────────────────────────
const AFP_NAMES = { 4: 'AFP CRECER', 5: 'AFP CONFIA', 6: 'IPSFA', 9: 'ISSS-IVM' };

const BANCO_NAMES = {
  1: 'BANCO CUSCATLAN', 2: 'BANCO AGRICOLA', 3: 'BANCO DAVIVIENDA',
  4: 'BANCO PROMERICA', 5: 'BANCO AMERICA CENTRAL', 7: 'BANCO HIPOTECARIO',
  9: 'CITIBANK', 10: 'BANCO ZAMERICANO', 11: 'BANCO G&T',
  12: 'BANCO DE FOMENTO AGROPECUARIO', 13: 'BANCO MULTIVALORES',
  16: 'PROCREDIT', 18: 'BANCO VIRTUAL', 23: 'BANCO AZUL',
  24: 'BANCO ATLANTIDA', 25: 'BANCO INDUSTRIAL',
};

// Brilo empEstadoCivil (int) → valor de nuestro sistema
const ESTADO_CIVIL = { 0: 'soltero', 1: 'casado', 2: 'divorciado', 3: 'viudo', 5: 'acompañado' };

// ── Mapeo depCodigo → codigo sucursal ─────────────────────────────────────────
const DEP_SUCURSAL_MAP = {
  'C_Administración': 'SUC-CM',
  'C_LOGISTICA':      'SUC-CM',
  'C_MANTTO':         'SUC-CM',
  'C_Mercadeo':       'SUC-CM',
  'C_Producción':     'SUC-CM',
  'C_Produccion_R':   'SUC-CM',
  'C_Ventas':         'SUC-CM',
  'DPT0001':          'SUC-CM',
  'DPT0004':          'SUC-CM',
  'PROD':             'SUC-CM',
  'RT-001':           'SUC-ZR',
  'DPT0002':          'SUC-AE1',
  'DPT0003':          'SUC-AE2',
  'MAL-AE':           'SUC0015',
  'DPT0006':          'SUC-PV',
  'DPT0007':          'SUC-SE',
  'DPT0008':          'SUC-HZ',
  'DPT0009':          'SUC-OP',
  'DTP00010':         'SUC-GU',
  'RT-003':           'SUC-LL',
};

// ── Mapeo depCodigo → departamento_id en RDS ──────────────────────────────────
const DEP_DEPARTAMENTO_MAP = {
  'C_LOGISTICA':      14,
  'C_MANTTO':         28,
  'C_Mercadeo':        9,
  'C_Producción':      6,
  'C_Produccion_R':   16,
  'C_Ventas':         30,
  'DPT0001':          15,
  'DPT0002':          25,
  'DPT0003':          26,
  'DPT0004':          30,
  'DPT0006':          22,
  'DPT0007':          23,
  'DPT0008':          19,
  'DPT0009':          21,
  'EVE-EXT':          29,
  'EVE-INT':          29,
  'MAL-AE':           27,
  'PROD':              6,
  'RT-001':           24,
  'RT-003':           20,
  'DTP00010':         18,
};

const DRY_RUN         = process.argv.includes('--dry-run');
const SKIP_EXPEDIENTE = process.argv.includes('--skip-expediente');
const BATCH           = 50;
const NOW             = new Date();
const AUD             = 'sync_empleados_to_rds.js';

const ts    = () => new Date().toTimeString().slice(0, 8);
const log   = s => console.log(`[${ts()}] ${s}`);
const clean = (s, max = 150) =>
  !s ? null : String(s).trim().replace(/\s+/g, ' ').slice(0, max) || null;

// Normaliza nombre de municipio para matching fuzzy (quita acentos, mayúsculas, puntuación)
function normMuni(s) {
  if (!s) return '';
  return String(s).toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9 ]/g, '')
    .trim().replace(/\s+/g, ' ');
}

// ── SQL Server: todos los empleados (activos e inactivos) ─────────────────────
const Q_EMPLEADOS = `
SELECT
  e.empCodigo       AS codigo,
  e.empNombres      AS nombres,
  e.empApellidos    AS apellidos,
  e.empEmail        AS email,
  c.crgCodigo       AS cargo_codigo,
  d.depCodigo       AS dep_codigo,
  CASE WHEN e.empActivo = 1 THEN 1 ELSE 0 END AS activo,
  CONVERT(date, e.empFechaIngreso)    AS fecha_ingreso,
  e.empSalarioMes                     AS salario_mes
FROM olComun.dbo.Empleados e WITH (NOLOCK)
INNER JOIN olComun.dbo.Cargos c WITH (NOLOCK) ON c.crgId = e.crgId
INNER JOIN olComun.dbo.Deptos d WITH (NOLOCK) ON d.depId = e.depId
WHERE e.empCodigo IS NOT NULL
ORDER BY e.empCodigo
`;

// ── SQL Server: cargos (solo los usados por algún empleado) ───────────────────
const Q_CARGOS = `
SELECT DISTINCT
  c.crgCodigo AS codigo,
  c.crgNombre AS nombre
FROM olComun.dbo.Cargos c WITH (NOLOCK)
INNER JOIN olComun.dbo.Empleados e WITH (NOLOCK) ON e.crgId = c.crgId
WHERE c.crgCodigo IS NOT NULL
ORDER BY c.crgCodigo
`;

// ── SQL Server: datos completos de expediente (solo activos) ──────────────────
const Q_EXPEDIENTE = `
SELECT
  e.empCodigo,
  -- Datos personales
  e.empFechaNacimiento,
  e.empSexo,
  e.empEstadoCivil,
  e.empTipoSangre,
  e.empLugarNacimiento,
  e.empProfesionDUI,
  e.empNivelAcademico,
  -- Contactos
  e.empCelular,
  e.empTelCasa,
  e.empNomAvisarA,
  e.empTelAvisarA,
  -- Banco
  e.empNumCuentaBco,
  e.bcoId,
  -- Documentos
  e.empISSS,
  e.empNumIPR,
  e.empNIT,
  e.iprId,
  ipr.iprNombreAbr,
  e.empDUI,
  e.empDuiFechaExpedicion,
  e.empDuiFechaExpiracion,
  e.muniIdDuiExpedicion,
  mExp.muniNombre        AS duiMuni,
  deExp.dptoNombre       AS duiDpto,
  deExp.dptoCodGobierno  AS duiDptoCod,
  -- Residencia
  mRes.muniNombre        AS resMuni,
  deRes.dptoNombre       AS resDpto,
  deRes.dptoCodGobierno  AS resDptoCod,
  e.empDireccion
FROM olComun.dbo.Empleados e WITH (NOLOCK)
LEFT JOIN olComun.dbo.InstPrevisional  ipr   WITH (NOLOCK) ON ipr.iprId   = e.iprId
LEFT JOIN olComun.dbo.MuniCondados     mExp  WITH (NOLOCK) ON mExp.muniId  = e.muniIdDuiExpedicion
LEFT JOIN olComun.dbo.DeptosEstados    deExp WITH (NOLOCK) ON deExp.dptoId = mExp.dptoId
LEFT JOIN olComun.dbo.MuniCondados     mRes  WITH (NOLOCK) ON mRes.muniId  = e.muniIdResidencia
LEFT JOIN olComun.dbo.DeptosEstados    deRes WITH (NOLOCK) ON deRes.dptoId = mRes.dptoId
WHERE e.empActivo = 1
  AND e.empCodigo IS NOT NULL
`;

// ─────────────────────────────────────────────────────────────────────────────
async function syncExpedientes(mssqlPool, pg, pgRrhh, codigoToId) {
  log('\n=== FASE 2: EXPEDIENTE ===');

  // 1. Catálogos geográficos de core_db (dptoCodGobierno "06" → geo_departamentos.id)
  log('Cargando catálogos geográficos...');
  const [geoDeptos, geoMunis, geoDists] = await Promise.all([
    pg.query('SELECT id, codigo FROM geo_departamentos'),
    pg.query('SELECT id, departamento_id, distrito_id, nombre FROM geo_municipios'),
    pg.query('SELECT id, departamento_id, nombre FROM geo_distritos'),
  ]);
  const geoDeptoByCod = Object.fromEntries(geoDeptos.rows.map(d => [d.codigo, Number(d.id)]));

  // Municipio: normMuni(nombre)+':'+departamento_id → {id, distrito_id}
  const geoMuniByKey = {};
  for (const m of geoMunis.rows) {
    geoMuniByKey[`${normMuni(m.nombre)}:${m.departamento_id}`] = {
      id: Number(m.id), distrito_id: m.distrito_id ? Number(m.distrito_id) : null,
    };
  }

  // Distrito (fallback): normMuni(nombre)+':'+departamento_id → distrito_id
  // Brilo usa nombres de distrito como "San Salvador Centro" como nivel de "municipio"
  const geoDistByKey = {};
  for (const d of geoDists.rows) {
    geoDistByKey[`${normMuni(d.nombre)}:${Number(d.departamento_id)}`] = Number(d.id);
  }

  function resolveGeo(muniNombre, dptoCod) {
    const deptoId = geoDeptoByCod[dptoCod] ?? null;
    if (!deptoId || !muniNombre) return { deptoId, muniId: null, distId: null };
    const key = `${normMuni(muniNombre)}:${deptoId}`;

    // 1. Municipio exacto
    const muniHit = geoMuniByKey[key];
    if (muniHit) return { deptoId, muniId: muniHit.id, distId: muniHit.distrito_id };

    // 2. Brilo usa nombre de distrito como municipio (ej. "San Salvador Centro")
    const distId = geoDistByKey[key] ?? null;
    if (distId) {
      // Intentar municipio "principal" del distrito quitando sufijo de orientación.
      // Ej: "San Salvador Centro" → "san salvador" → municipio San Salvador (id=110).
      const stripped = normMuni(muniNombre).replace(/\s+(norte|sur|este|oeste|centro|occidente|oriente)$/, '');
      const mainMuni = geoMuniByKey[`${stripped}:${deptoId}`];
      return { deptoId, muniId: mainMuni?.id ?? null, distId };
    }

    return { deptoId, muniId: null, distId: null };
  }

  // 2. Consultar Brilo
  log('Consultando Brilo (expediente)...');
  const { recordset: briloEmps } = await mssqlPool.request().query(Q_EXPEDIENTE);
  log(`Empleados activos en Brilo: ${briloEmps.length}`);

  // 3. Limpiar duplicados exactos generados por runs anteriores
  if (!DRY_RUN) {
    // Direcciones: conserva el registro más antiguo (menor id) por (empleado_id, tipo, texto)
    const dupDir = await pgRrhh.query(`
      DELETE FROM expediente_direcciones
      WHERE id NOT IN (
        SELECT MIN(id) FROM expediente_direcciones
        GROUP BY empleado_id, tipo, LOWER(TRIM(COALESCE(direccion, '')))
      )
    `);
    if (dupDir.rowCount > 0) log(`  Direcciones duplicadas eliminadas: ${dupDir.rowCount}`);

    // Contactos: conserva el registro más antiguo por (empleado_id, valor normalizado)
    const dupCont = await pgRrhh.query(`
      DELETE FROM expediente_contactos
      WHERE id NOT IN (
        SELECT MIN(id) FROM expediente_contactos
        GROUP BY empleado_id, LOWER(TRIM(COALESCE(valor, '')))
      )
    `);
    if (dupCont.rowCount > 0) log(`  Contactos duplicados eliminados: ${dupCont.rowCount}`);
  }

  // 4. Cargar estado actual de rrhh_db (tras cleanup)
  const [dpRows, docsRows, contRows, bancoRows, dirRows] = await Promise.all([
    pgRrhh.query('SELECT empleado_id, fecha_nacimiento, genero, estado_civil, lugar_nacimiento, grupo_sanguineo, profesion, domicilio, nacionalidad FROM expediente_datos_personales'),
    pgRrhh.query('SELECT id, empleado_id, tipo, numero, lugar_exp_texto, lugar_exp_municipio_id, fecha_emision, fecha_vencimiento FROM expediente_documentos'),
    pgRrhh.query('SELECT empleado_id, tipo, valor, es_emergencia FROM expediente_contactos'),
    pgRrhh.query('SELECT empleado_id, numero_cuenta FROM expediente_cuentas_banco'),
    pgRrhh.query('SELECT id, empleado_id, municipio_id, distrito_id, departamento_id FROM expediente_direcciones'),
  ]);

  const dpMap = {};
  dpRows.rows.forEach(d => { dpMap[d.empleado_id] = d; });

  const docSet = new Set(docsRows.rows.map(d => `${d.empleado_id}:${d.tipo}`));
  const duiMap = {};
  docsRows.rows.filter(d => d.tipo === 'dui').forEach(d => {
    duiMap[d.empleado_id] = { id: d.id, lugar: d.lugar_exp_texto, muniId: d.lugar_exp_municipio_id, fecha: d.fecha_emision, venc: d.fecha_vencimiento };
  });

  // Dedup contactos por VALOR (no por tipo+valor): evita duplicar mismo número con distinto tipo
  // Todos los IDs normalizados a Number para que Set/Map funcionen con los bigint de empleados.id
  const contValSet = new Set(contRows.rows.map(c => `${c.empleado_id}:${(c.valor||'').trim()}`));
  const emergSet   = new Set(contRows.rows.filter(c => c.es_emergencia).map(c => Number(c.empleado_id)));

  const bancoSet = new Set(bancoRows.rows.map(b => `${b.empleado_id}:${(b.numero_cuenta||'').trim()}`));

  // Empleados con al menos una dirección (Number para comparar con empId que es Number)
  const tieneDir  = new Set(dirRows.rows.map(d => Number(d.empleado_id)));
  // Primer dirId por empleado sin geo IDs completos (municipio_id O distrito_id faltante)
  const dirSinGeo = new Map();
  for (const d of dirRows.rows) {
    const eid = Number(d.empleado_id);
    if ((!d.municipio_id || !d.distrito_id) && !dirSinGeo.has(eid)) dirSinGeo.set(eid, d.id);
  }

  let dpActualiz = 0, docsIns = 0, duiActualiz = 0, contIns = 0, bancoIns = 0, dirIns = 0, dirGeoActualiz = 0, sinMatch = 0;

  const ahora = NOW.toISOString();

  for (const emp of briloEmps) {
    const codigo = String(emp.empCodigo || '').trim();
    const empId  = codigoToId[codigo];
    if (!empId) { sinMatch++; continue; }

    // ── Geo IDs para este empleado ─────────────────────────────────────────────
    const geoExp = resolveGeo(emp.duiMuni, emp.duiDptoCod);
    const geoRes = resolveGeo(emp.resMuni, emp.resDptoCod);

    // ── Datos personales ──────────────────────────────────────────────────────
    const dp = dpMap[empId];
    if (dp) {
      const updates = {};
      const fechaNac  = fmtDate(emp.empFechaNacimiento);
      const genero    = emp.empSexo === 'M' ? 'masculino' : emp.empSexo === 'F' ? 'femenino' : null;
      const estadoCiv = ESTADO_CIVIL[emp.empEstadoCivil] ?? null;
      const lugarNac  = clean(emp.empLugarNacimiento, 200);
      const grupoSang = clean(emp.empTipoSangre, 10);
      const profesion = clean(emp.empProfesionDUI, 150);
      const domBrilo  = [emp.resMuni, emp.resDpto].filter(Boolean).join(', ') || null;

      if (fechaNac  && !dp.fecha_nacimiento)        updates.fecha_nacimiento = fechaNac;
      if (genero    && !dp.genero?.trim())           updates.genero = genero;
      if (estadoCiv && !dp.estado_civil?.trim())     updates.estado_civil = estadoCiv;
      if (lugarNac  && !dp.lugar_nacimiento?.trim()) updates.lugar_nacimiento = lugarNac;
      if (grupoSang && !dp.grupo_sanguineo?.trim())  updates.grupo_sanguineo = grupoSang;
      if (profesion && !dp.profesion?.trim())        updates.profesion = profesion;
      if (domBrilo  && !dp.domicilio?.trim())        updates.domicilio = domBrilo;
      if (!dp.nacionalidad?.trim())                  updates.nacionalidad = 'salvadoreña';

      if (Object.keys(updates).length) {
        const setCols = Object.keys(updates);
        const vals    = Object.values(updates);
        vals.push(ahora, empId);
        const setClause = setCols.map((c, i) => `${c} = $${i + 1}`).join(', ');
        if (!DRY_RUN) await pgRrhh.query(
          `UPDATE expediente_datos_personales SET ${setClause}, updated_at = $${vals.length - 1} WHERE empleado_id = $${vals.length}`,
          vals
        );
        dpActualiz++;
      }
    }

    // ── Documentos: ISSS ──────────────────────────────────────────────────────
    if (emp.empISSS?.trim() && !docSet.has(`${empId}:isss`)) {
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_documentos (empleado_id, tipo, numero, notas, created_at, updated_at)
         VALUES ($1, 'isss', $2, 'sync:brilo', $3, $3)`,
        [empId, emp.empISSS.trim(), ahora]
      );
      docSet.add(`${empId}:isss`);
      docsIns++;
    }

    // ── Documentos: AFP ───────────────────────────────────────────────────────
    if (emp.empNumIPR?.trim() && !docSet.has(`${empId}:afp`)) {
      const afpNombre = emp.iprNombreAbr || AFP_NAMES[emp.iprId] || 'AFP';
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_documentos (empleado_id, tipo, numero, entidad_emisora, notas, created_at, updated_at)
         VALUES ($1, 'afp', $2, $3, 'sync:brilo', $4, $4)`,
        [empId, emp.empNumIPR.trim(), afpNombre, ahora]
      );
      docSet.add(`${empId}:afp`);
      docsIns++;
    }

    // ── Documentos: NIT ───────────────────────────────────────────────────────
    if (emp.empNIT?.trim() && !docSet.has(`${empId}:nit`)) {
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_documentos (empleado_id, tipo, numero, notas, created_at, updated_at)
         VALUES ($1, 'nit', $2, 'sync:brilo', $3, $3)`,
        [empId, emp.empNIT.trim(), ahora]
      );
      docSet.add(`${empId}:nit`);
      docsIns++;
    }

    // ── Documentos: DUI (lugar, fecha, vencimiento, municipio_id) ─────────────
    if (duiMap[empId]) {
      const dui        = duiMap[empId];
      const lugarBrilo = [emp.duiMuni, emp.duiDpto].filter(Boolean).join(', ') || null;
      const fechaExp   = fmtDate(emp.empDuiFechaExpedicion);
      const fechaVenc  = fmtDate(emp.empDuiFechaExpiracion);

      const needsLugar = lugarBrilo  && !dui.lugar?.trim();
      const needsFecha = fechaExp    && !dui.fecha;
      const needsVenc  = fechaVenc   && !dui.venc;
      const needsMuni  = geoExp.muniId && !dui.muniId;

      if (needsLugar || needsFecha || needsVenc || needsMuni) {
        if (!DRY_RUN) await pgRrhh.query(
          `UPDATE expediente_documentos
           SET lugar_exp_texto       = COALESCE(NULLIF(lugar_exp_texto,''), $1),
               lugar_exp_municipio_id = COALESCE(lugar_exp_municipio_id, $2),
               fecha_emision         = COALESCE(fecha_emision, $3),
               fecha_vencimiento     = COALESCE(fecha_vencimiento, $4),
               updated_at            = $5
           WHERE id = $6`,
          [lugarBrilo, geoExp.muniId, fechaExp, fechaVenc, ahora, dui.id]
        );
        duiActualiz++;
      }
    }

    // ── Contactos: celular (dedup por valor, no por tipo+valor) ───────────────
    const celular = clean(emp.empCelular, 30);
    if (celular && !contValSet.has(`${empId}:${celular}`)) {
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_contactos (empleado_id, tipo, etiqueta, valor, es_emergencia, orden, created_at, updated_at)
         VALUES ($1, 'celular', 'Celular', $2, false, 1, $3, $3)`,
        [empId, celular, ahora]
      );
      contValSet.add(`${empId}:${celular}`);
      contIns++;
    }

    // ── Contactos: teléfono casa (solo si valor distinto al celular) ──────────
    const telCasa = clean(emp.empTelCasa, 30);
    if (telCasa && !contValSet.has(`${empId}:${telCasa}`)) {
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_contactos (empleado_id, tipo, etiqueta, valor, es_emergencia, orden, created_at, updated_at)
         VALUES ($1, 'telefono', 'Casa', $2, false, 2, $3, $3)`,
        [empId, telCasa, ahora]
      );
      contValSet.add(`${empId}:${telCasa}`);
      contIns++;
    }

    // ── Contactos: emergencia ─────────────────────────────────────────────────
    const nomEmerg = clean(emp.empNomAvisarA, 150);
    const telEmerg = clean(emp.empTelAvisarA, 30);
    if (nomEmerg && telEmerg && !emergSet.has(empId) && !contValSet.has(`${empId}:${telEmerg}`)) {
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_contactos (empleado_id, tipo, etiqueta, valor, nombre_contacto, es_emergencia, orden, created_at, updated_at)
         VALUES ($1, 'telefono', 'Emergencia', $2, $3, true, 10, $4, $4)`,
        [empId, telEmerg, nomEmerg, ahora]
      );
      emergSet.add(empId);
      contValSet.add(`${empId}:${telEmerg}`);
      contIns++;
    }

    // ── Cuenta bancaria ───────────────────────────────────────────────────────
    const numCuenta = clean(emp.empNumCuentaBco, 50);
    if (numCuenta && numCuenta.toUpperCase() !== 'CHEQUE'
        && emp.bcoId && !bancoSet.has(`${empId}:${numCuenta}`)) {
      const bancoNombre = BANCO_NAMES[emp.bcoId] || `BANCO ${emp.bcoId}`;
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_cuentas_banco (empleado_id, banco, tipo_cuenta, numero_cuenta, es_principal, aud_usuario, created_at, updated_at)
         VALUES ($1, $2, 'ahorros', $3, true, 'sync:brilo', $4, $4)`,
        [empId, bancoNombre, numCuenta, ahora]
      );
      bancoSet.add(`${empId}:${numCuenta}`);
      bancoIns++;
    }

    // ── Dirección de residencia ───────────────────────────────────────────────
    const domBrilo = [emp.resMuni, emp.resDpto].filter(Boolean).join(', ');
    const calle    = clean(emp.empDireccion, 300) || domBrilo;

    if (!tieneDir.has(empId) && calle) {
      // No tiene ninguna dirección → insertar con IDs geo
      if (!DRY_RUN) await pgRrhh.query(
        `INSERT INTO expediente_direcciones
           (empleado_id, tipo, direccion, municipio, departamento_geo,
            departamento_id, distrito_id, municipio_id, es_principal, created_at, updated_at)
         VALUES ($1, 'residencia', $2, $3, $4, $5, $6, $7, true, $8, $8)`,
        [empId, calle, emp.resMuni || '', emp.resDpto || '',
         geoRes.deptoId, geoRes.distId, geoRes.muniId, ahora]
      );
      tieneDir.add(empId);
      dirIns++;
    } else if (tieneDir.has(empId) && dirSinGeo.has(empId) && geoRes.deptoId) {
      // Ya tiene dirección pero le faltan los IDs geo → actualizar
      if (!DRY_RUN) await pgRrhh.query(
        `UPDATE expediente_direcciones
         SET departamento_id = COALESCE(departamento_id, $1),
             distrito_id     = COALESCE(distrito_id, $2),
             municipio_id    = COALESCE(municipio_id, $3),
             updated_at      = $4
         WHERE id = $5`,
        [geoRes.deptoId, geoRes.distId, geoRes.muniId, ahora, dirSinGeo.get(empId)]
      );
      dirSinGeo.delete(empId);
      dirGeoActualiz++;
    }
  }

  log(`Datos personales actualizados   : ${dpActualiz}`);
  log(`Documentos insertados           : ${docsIns}`);
  log(`DUI lugar/fecha/id actualizados : ${duiActualiz}`);
  log(`Contactos insertados            : ${contIns}`);
  log(`Cuentas bancarias insertadas    : ${bancoIns}`);
  log(`Direcciones creadas             : ${dirIns}`);
  log(`Direcciones geo actualizadas    : ${dirGeoActualiz}`);
  log(`Sin match en core_db            : ${sinMatch}`);
  if (DRY_RUN) log('[DRY RUN — no se escribió nada en expediente]');
}

// ─────────────────────────────────────────────────────────────────────────────
async function run() {
  if (DRY_RUN) log('=== MODO DRY-RUN: sin cambios en BD ===');

  log('Conectando a SQL Server...');
  const mssqlPool = await sql.connect(MSSQL_CFG);
  log('SQL Server OK.');

  log('Conectando a PostgreSQL RDS (core_db)...');
  let pg;
  for (let attempt = 1; attempt <= 8; attempt++) {
    const p = new Pool(PG_CFG);
    try {
      await p.query('SELECT 1');
      pg = p;
      log('PostgreSQL core_db OK.');
      break;
    } catch (e) {
      await p.end().catch(() => {});
      if (attempt === 8) throw new Error(`PG no disponible tras 8 intentos: ${e.message}`);
      log(`  PG intento ${attempt} fallido — reintentando en 10s...`);
      await new Promise(r => setTimeout(r, 10000));
    }
  }

  log('Conectando a PostgreSQL RDS (rrhh_db)...');
  const pgRrhh = new Pool(PG_RRHH_CFG);
  await pgRrhh.query('SELECT 1');
  log('PostgreSQL rrhh_db OK.\n');

  try {
    // ── FASE 1: CARGOS ────────────────────────────────────────────────────────
    log('=== FASE 1A: CARGOS ===');
    const cargosRows = (await mssqlPool.request().query(Q_CARGOS)).recordset;
    log(`SQL Server: ${cargosRows.length} cargos`);

    if (!DRY_RUN && cargosRows.length) {
      for (let i = 0; i < cargosRows.length; i += BATCH) {
        const batch = cargosRows.slice(i, i + BATCH);
        const params = [];
        const rowParts = batch.map(r => {
          params.push(clean(r.codigo, 30), clean(r.nombre, 120), true, AUD, NOW, NOW);
          const n = params.length;
          return `($${n-5},$${n-4},$${n-3},$${n-2},$${n-1},$${n})`;
        });
        await pg.query(
          `INSERT INTO cargos (codigo, nombre, activo, aud_usuario, created_at, updated_at)
           VALUES ${rowParts.join(',')}
           ON CONFLICT (codigo) DO UPDATE SET nombre = EXCLUDED.nombre, updated_at = EXCLUDED.updated_at`,
          params
        );
      }
      await pg.query(`SELECT setval('cargos_id_seq', (SELECT COALESCE(MAX(id), 1) FROM cargos))`);
      log(`Cargos upsertados: ${cargosRows.length}\n`);
    } else {
      log(`(dry-run) Se procesarían ${cargosRows.length} cargos\n`);
    }

    // ── FASE 1: EMPLEADOS ─────────────────────────────────────────────────────
    log('=== FASE 1B: EMPLEADOS ===');

    const [sucRes, cargoRes] = await Promise.all([
      pg.query('SELECT id, codigo FROM sucursales'),
      pg.query('SELECT id, codigo FROM cargos'),
    ]);
    const sucByCode   = Object.fromEntries(sucRes.rows.map(r => [r.codigo, r.id]));
    const cargoByCode = Object.fromEntries(cargoRes.rows.map(r => [r.codigo, r.id]));
    log(`Sucursales en RDS: ${sucRes.rows.length}`);
    log(`Cargos en RDS:     ${cargoRes.rows.length}`);

    const existRes = await pg.query('SELECT codigo, sync_excluido, departamento_id FROM empleados');
    const existentes = new Set(existRes.rows.map(r => r.codigo));
    const excluidos  = new Set(existRes.rows.filter(r => r.sync_excluido).map(r => r.codigo));
    const deptActual = Object.fromEntries(
      existRes.rows.filter(r => r.departamento_id).map(r => [r.codigo, parseInt(r.departamento_id)])
    );
    log(`Empleados ya en RDS: ${existentes.size}\n`);

    const deptHierRes = await pg.query('SELECT id, parent_id FROM departamentos WHERE activo = true');
    const deptParentMap = {};
    for (const d of deptHierRes.rows)
      deptParentMap[parseInt(d.id)] = d.parent_id ? parseInt(d.parent_id) : null;

    function isChildDept(deptId, ancestorId) {
      if (!deptId || !ancestorId) return false;
      let cur = deptParentMap[deptId];
      while (cur) {
        if (cur === ancestorId) return true;
        cur = deptParentMap[cur];
      }
      return false;
    }

    const empRows = (await mssqlPool.request().query(Q_EMPLEADOS)).recordset;
    log(`SQL Server: ${empRows.length} empleados (activos + inactivos)`);

    const paraInsertar   = [];
    const paraActualizar = [];
    let sinDepMap = 0;

    for (const r of empRows) {
      const codigo         = clean(r.codigo, 20);
      const activo         = r.activo === 1 || r.activo === true;
      const departamentoId = DEP_DEPARTAMENTO_MAP[r.dep_codigo] ?? null;
      const sucCodigo      = DEP_SUCURSAL_MAP[r.dep_codigo] ?? null;
      const sucursalId     = sucCodigo ? (sucByCode[sucCodigo] ?? null) : null;

      if (existentes.has(codigo)) {
        if (excluidos.has(codigo)) continue;
        const fechaIngreso = fmtDate(r.fecha_ingreso);
        const cargoId      = cargoByCode[r.cargo_codigo] ?? null;
        const salarioBase  = r.salario_mes != null ? parseFloat(r.salario_mes) : null;
        const emailVal     = clean(r.email, 150);

        const curDept  = deptActual[codigo] ?? null;
        const deptFinal = (curDept && isChildDept(curDept, departamentoId))
          ? curDept : departamentoId;

        paraActualizar.push([deptFinal, sucursalId, fechaIngreso, cargoId, salarioBase, emailVal, codigo]);
      } else if (activo) {
        const cargoId = cargoByCode[r.cargo_codigo] ?? null;
        if (!sucCodigo) sinDepMap++;
        paraInsertar.push([
          codigo, clean(r.nombres, 100), clean(r.apellidos, 100), clean(r.email, 150),
          cargoId, sucursalId, departamentoId, activo,
          fmtDate(r.fecha_ingreso), r.salario_mes != null ? parseFloat(r.salario_mes) : null,
          AUD, NOW, NOW,
        ]);
      }
    }

    log(`  Para insertar (nuevos):          ${paraInsertar.length}`);
    log(`  Para actualizar (existentes):    ${paraActualizar.length}`);
    if (excluidos.size) log(`  Excluidos del sync (duplicados):  ${excluidos.size}`);
    if (sinDepMap) log(`  AVISO: ${sinDepMap} nuevos sin mapeo de sucursal`);

    if (!DRY_RUN) {
      if (paraActualizar.length) {
        let updOk = 0;
        for (let i = 0; i < paraActualizar.length; i += BATCH) {
          const batch = paraActualizar.slice(i, i + BATCH);
          for (const [dId, sId, fi, cId, sb, em, codigo] of batch) {
            await pg.query(
              `UPDATE empleados
               SET departamento_id = COALESCE($1, departamento_id),
                   sucursal_id     = COALESCE($2, sucursal_id),
                   fecha_ingreso   = COALESCE(fecha_ingreso, $3),
                   cargo_id        = COALESCE($4, cargo_id),
                   salario_base    = COALESCE(salario_base, $5),
                   email           = COALESCE(NULLIF(email, ''), $6),
                   updated_at      = $7
               WHERE codigo = $8`,
              [dId, sId, fi, cId, sb, em, NOW, codigo]
            );
            updOk++;
          }
          process.stdout.write(`\r  Actualizados: ${updOk}/${paraActualizar.length}  `);
        }
        console.log();
        log(`Datos actualizados en ${updOk} empleados existentes.`);
      }

      if (paraInsertar.length) {
        const cols = ['codigo', 'nombres', 'apellidos', 'email', 'cargo_id',
                      'sucursal_id', 'departamento_id', 'activo', 'fecha_ingreso',
                      'salario_base', 'aud_usuario', 'created_at', 'updated_at'];
        let insOk = 0;
        for (let i = 0; i < paraInsertar.length; i += BATCH) {
          const batch  = paraInsertar.slice(i, i + BATCH);
          const params = [];
          const rowParts = batch.map(row => {
            const ph = row.map(v => { params.push(v); return `$${params.length}`; });
            return `(${ph.join(',')})`;
          });
          await pg.query(
            `INSERT INTO empleados (${cols.join(',')}) VALUES ${rowParts.join(',')}
             ON CONFLICT (codigo) DO NOTHING`,
            params
          );
          insOk += batch.length;
          process.stdout.write(`\r  Insertados: ${insOk}/${paraInsertar.length}  `);
        }
        console.log();
        await pg.query(`SELECT setval('empleados_id_seq', (SELECT COALESCE(MAX(id), 1) FROM empleados))`);
        log(`${insOk} empleados nuevos insertados.`);
      }

      // Crear ingresos_personal para empleados nuevos
      if (paraInsertar.length) {
        try {
          const codigos = paraInsertar.map(r => r[0]);
          const empRes  = await pg.query(
            `SELECT e.id, e.nombres, e.apellidos, e.fecha_ingreso,
                    c.nombre AS cargo_nombre, s.nombre AS sucursal_nombre
             FROM empleados e
             LEFT JOIN cargos c     ON c.id = e.cargo_id
             LEFT JOIN sucursales s ON s.id = e.sucursal_id
             WHERE e.codigo = ANY($1)`,
            [codigos]
          );
          if (empRes.rows.length) {
            const empIds = empRes.rows.map(r => r.id);
            const yaExistenRes = await pgRrhh.query(
              `SELECT DISTINCT empleado_id FROM ingresos_personal WHERE empleado_id = ANY($1)`,
              [empIds]
            );
            const yaExisten = new Set(yaExistenRes.rows.map(r => r.empleado_id));
            const sinIngreso = empRes.rows.filter(r => !yaExisten.has(r.id));
            if (sinIngreso.length) {
              const params = [];
              const rowParts = sinIngreso.map(r => {
                const nombre = `${r.nombres} ${r.apellidos}`.trim();
                params.push(r.id, nombre, r.cargo_nombre, r.sucursal_nombre,
                            fmtDate(r.fecha_ingreso), 'pendiente', AUD, NOW, NOW);
                const n = params.length;
                return `($${n-8},$${n-7},$${n-6},$${n-5},$${n-4},$${n-3},$${n-2},$${n-1},$${n})`;
              });
              await pgRrhh.query(
                `INSERT INTO ingresos_personal
                   (empleado_id, empleado_nombre, cargo_nombre, sucursal_nombre,
                    fecha_ingreso, confirmacion, aud_usuario, created_at, updated_at)
                 VALUES ${rowParts.join(',')}`,
                params
              );
              log(`${sinIngreso.length} registro(s) de ingreso creados en ingresos_personal.`);
            }
          }
        } catch (e) {
          log(`AVISO: No se pudieron crear ingresos_personal automáticos: ${e.message}`);
        }
      }

      const total   = await pg.query('SELECT COUNT(*) FROM empleados');
      const activos = await pg.query('SELECT COUNT(*) FROM empleados WHERE activo = true');
      log('\n==========================================');
      log('RESUMEN FASE 1');
      log(`  Empleados en RDS (total):  ${total.rows[0].count}`);
      log(`  Empleados activos:         ${activos.rows[0].count}`);
      log(`  Insertados esta ejecución: ${paraInsertar.length}`);
      log(`  Actualizados esta ejecución: ${paraActualizar.length}`);
      log('==========================================');
    } else {
      log('\n(dry-run) Sin cambios. Ejecuta sin --dry-run para aplicar.');
    }

    // ── FASE 2: EXPEDIENTE ────────────────────────────────────────────────────
    if (!SKIP_EXPEDIENTE) {
      // Construir mapa codigo → id (después de insertar posibles nuevos empleados)
      const idRes = await pg.query('SELECT id, codigo FROM empleados WHERE activo = true AND codigo IS NOT NULL');
      // Number() normaliza bigint-string ("123") a number para que Set.has() funcione
      // con los integer-type de las tablas rrhh_db (expediente_*)
      const codigoToId = Object.fromEntries(idRes.rows.map(r => [String(r.codigo).trim(), Number(r.id)]));
      await syncExpedientes(mssqlPool, pg, pgRrhh, codigoToId);
    } else {
      log('\n[--skip-expediente] Fase 2 omitida.');
    }

  } finally {
    await Promise.all([mssqlPool.close(), pg.end(), pgRrhh.end()]).catch(() => {});
    log('Conexiones cerradas.');
  }
}

run().catch(err => {
  console.error('\nERROR:', err.message ?? err);
  process.exit(1);
});
