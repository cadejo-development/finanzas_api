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
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

const PG_CORE = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'core_db', user: 'cadejo_admin', password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
};

const PG_RRHH = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'rrhh_db', user: 'cadejo_admin', password: 'Holamundo#3..',
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
        RTRIM(e.empTelAvisarA)      AS tel_avisar
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

    // ── 3. Leer estado actual de expediente_datos_personales ─────────────────
    log('Cargando expediente_datos_personales...');
    const { rows: expRows } = await pgRrhh.query(
      'SELECT empleado_id, genero, fecha_nacimiento, estado_civil, grupo_sanguineo, lugar_nacimiento FROM expediente_datos_personales'
    );
    const expPorId = Object.fromEntries(expRows.map(r => [r.empleado_id, r]));
    log(`rrhh_db: ${expRows.length} registros existentes en expediente_datos_personales\n`);

    // ── 4. Leer contactos existentes (celular/casa/emergencia ya cargados) ────
    log('Cargando expediente_contactos...');
    const { rows: ctRows } = await pgRrhh.query(
      `SELECT empleado_id, etiqueta FROM expediente_contactos
       WHERE etiqueta IN ('celular', 'casa', 'emergencia')`
    );
    // Set de "empleado_id:etiqueta" ya existentes
    const ctExistentes = new Set(ctRows.map(r => `${r.empleado_id}:${r.etiqueta}`));
    log(`rrhh_db: ${ctRows.length} contactos existentes (celular/casa/emergencia)\n`);

    // ── 5. Calcular inserciones/actualizaciones para datos personales ─────────
    const dpInsertar   = [];
    const dpActualizar = [];
    const ctInsertar   = [];
    let sinMatch = 0;

    for (const row of recordset) {
      const empId = idPorCodigo[row.codigo];
      if (!empId) { sinMatch++; continue; }

      // ── Datos personales ──────────────────────────────────────────────────
      const generoNuevo    = row.sexo         ? (SEXO_MAP[row.sexo] ?? null)                    : null;
      const fechaNueva     = row.fecha_nacimiento ? toDate(row.fecha_nacimiento)                 : null;
      const estadoCivil    = row.estado_civil != null ? (ESTADO_CIVIL_MAP[row.estado_civil] ?? null) : null;
      const grupoSanguineo = clean(row.tipo_sangre, 10);
      const lugarNac       = clean(row.lugar_nacimiento, 150);

      const exp = expPorId[empId];

      if (!exp) {
        if (generoNuevo || fechaNueva || estadoCivil || grupoSanguineo || lugarNac) {
          dpInsertar.push({ empleado_id: empId, genero: generoNuevo, fecha_nacimiento: fechaNueva, estado_civil: estadoCivil, grupo_sanguineo: grupoSanguineo, lugar_nacimiento: lugarNac });
        }
      } else {
        const campos = {};
        if (!exp.genero           && generoNuevo)    campos.genero           = generoNuevo;
        if (!exp.fecha_nacimiento && fechaNueva)      campos.fecha_nacimiento = fechaNueva;
        if (!exp.estado_civil     && estadoCivil)     campos.estado_civil     = estadoCivil;
        if (!exp.grupo_sanguineo  && grupoSanguineo)  campos.grupo_sanguineo  = grupoSanguineo;
        if (!exp.lugar_nacimiento && lugarNac)         campos.lugar_nacimiento = lugarNac;
        if (Object.keys(campos).length) dpActualizar.push({ empleado_id: empId, ...campos });
      }

      // ── Contactos ─────────────────────────────────────────────────────────
      const celular  = clean(row.celular, 30);
      const telCasa  = clean(row.tel_casa, 30);
      const nomAvisa = clean(row.nom_avisar, 100);
      const telAvisa = clean(row.tel_avisar, 30);

      if (celular && !ctExistentes.has(`${empId}:celular`)) {
        ctInsertar.push({ empleado_id: empId, tipo: 'telefono', etiqueta: 'celular', valor: celular, es_emergencia: false, nombre_contacto: null });
      }
      if (telCasa && !ctExistentes.has(`${empId}:casa`)) {
        ctInsertar.push({ empleado_id: empId, tipo: 'telefono', etiqueta: 'casa', valor: telCasa, es_emergencia: false, nombre_contacto: null });
      }
      // Emergencia: solo si hay teléfono (valor no puede ser null)
      if (telAvisa && !ctExistentes.has(`${empId}:emergencia`)) {
        ctInsertar.push({ empleado_id: empId, tipo: 'telefono', etiqueta: 'emergencia', valor: telAvisa, es_emergencia: true, nombre_contacto: nomAvisa });
      }
    }

    log('──────────────────────────────────────────────────────');
    log(`Datos personales INSERTAR (sin registro): ${dpInsertar.length}`);
    log(`Datos personales ACTUALIZAR (null→valor): ${dpActualizar.length}`);
    log(`Contactos        INSERTAR (nuevos):       ${ctInsertar.length}`);
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
           (empleado_id, genero, fecha_nacimiento, estado_civil, grupo_sanguineo, lugar_nacimiento, aud_usuario, created_at, updated_at)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$8)
         ON CONFLICT (empleado_id) DO NOTHING`,
        [r.empleado_id, r.genero, r.fecha_nacimiento, r.estado_civil, r.grupo_sanguineo, r.lugar_nacimiento, AUD, NOW]
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
      const campos = ['genero','fecha_nacimiento','estado_civil','grupo_sanguineo','lugar_nacimiento'];
      for (const c of campos) {
        if (r[c] != null) { params.push(r[c]); sets.push(`${c} = $${params.length}`); }
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

    // ── 8. Insertar contactos nuevos ─────────────────────────────────────────
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

    // ── 9. Resumen ────────────────────────────────────────────────────────────
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
