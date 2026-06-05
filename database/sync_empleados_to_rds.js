/**
 * sync_empleados_to_rds.js
 *
 * SQL Server (olcomun) → RDS PostgreSQL (core_db)
 *
 * Reglas de sincronización:
 *   - Empleado YA existe en RDS  (match por codigo) → solo actualiza campo "activo"
 *   - Empleado NO existe en RDS                     → inserta con todos los campos
 *
 * Incluye empleados ACTIVOS e INACTIVOS del SQL Server para poder
 * reflejar bajas (empActivo = 0).
 *
 * Uso:
 *   node sync_empleados_to_rds.js             (ejecuta)
 *   node sync_empleados_to_rds.js --dry-run   (muestra conteos, no escribe)
 */

const sql      = require('mssql');
const { Pool } = require('pg');

// ── SQL Server ────────────────────────────────────────────────────────────────
const MSSQL_CFG = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

// ── RDS PostgreSQL core_db ────────────────────────────────────────────────────
const PG_CFG = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'core_db', user: 'cadejo_admin',
  password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
  connectionTimeoutMillis: 30000,
  idleTimeoutMillis: 90000,
};

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
  'MAL-AE':           'SUC0015',  // RESTAURANTE MALCRIADAS (id=16)
  'DPT0006':          'SUC-PV',
  'DPT0007':          'SUC-SE',
  'DPT0008':          'SUC-HZ',
  'DPT0009':          'SUC-OP',
  'DTP00010':         'SUC-GU',
  'RT-003':           'SUC-LL',
};

// ── Mapeo depCodigo → departamento_id en RDS ──────────────────────────────────
const DEP_DEPARTAMENTO_MAP = {
  'C_LOGISTICA':      14,   // LOGÍSTICA
  'C_MANTTO':         28,   // MANTENIMIENTO
  'C_Mercadeo':        9,   // MERCADEO
  'C_Producción':      6,   // PRODUCCIÓN
  'C_Produccion_R':   16,   // CENTRO DE PRODUCCIÓN - RESTAURANTES
  'C_Ventas':         30,   // VENTAS
  'DPT0001':          15,   // BODEGA
  'DPT0002':          25,   // RESTAURANTE AEROPUERTO 1
  'DPT0003':          26,   // RESTAURANTE AEROPUERTO 2
  'DPT0004':          30,   // VENTAS (eventuales)
  'DPT0006':          22,   // RESTAURANTE PASEO VENECIA
  'DPT0007':          23,   // RESTAURANTE SANTA ELENA
  'DPT0008':          19,   // RESTAURANTE MONTAÑA (antes Huizúcar)
  'DPT0009':          21,   // RESTAURANTE OPICO
  'EVE-EXT':          29,   // EVENTOS
  'EVE-INT':          29,   // EVENTOS
  'MAL-AE':           27,   // RESTAURANTE MALCRIADAS AE
  'PROD':              6,   // PRODUCCIÓN
  'RT-001':           24,   // RESTAURANTE ZONA ROSA
  'RT-003':           20,   // RESTAURANTE LA LIBERTAD
  'DTP00010':         18,   // RESTAURANTE CASA GUIROLA (código RES_GUI)
};

const DRY_RUN = process.argv.includes('--dry-run');
const BATCH   = 50;
const NOW     = new Date();
const AUD     = 'sync_empleados_to_rds.js';

const ts    = () => new Date().toTimeString().slice(0, 8);
const log   = s => console.log(`[${ts()}] ${s}`);
const clean = (s, max = 150) =>
  !s ? null : String(s).trim().replace(/\s+/g, ' ').slice(0, max) || null;

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

// ─────────────────────────────────────────────────────────────────────────────
async function run() {
  if (DRY_RUN) log('=== MODO DRY-RUN: sin cambios en BD ===');

  // ── Conectar SQL Server ───────────────────────────────────────────────────
  log('Conectando a SQL Server...');
  const mssqlPool = await sql.connect(MSSQL_CFG);
  log('SQL Server OK.');

  // ── Conectar PostgreSQL (con reintentos) ──────────────────────────────────
  log('Conectando a PostgreSQL RDS...');
  let pg;
  for (let attempt = 1; attempt <= 8; attempt++) {
    const p = new Pool(PG_CFG);
    try {
      await p.query('SELECT 1');
      pg = p;
      log('PostgreSQL OK.\n');
      break;
    } catch (e) {
      await p.end().catch(() => {});
      if (attempt === 8) throw new Error(`PG no disponible tras 8 intentos: ${e.message}`);
      log(`  PG intento ${attempt} fallido — reintentando en 10s...`);
      await new Promise(r => setTimeout(r, 10000));
    }
  }

  try {
    // ── 1. Sincronizar cargos (upsert completo — son catálogo) ───────────────
    log('=== CARGOS ===');
    const cargosRows = (await mssqlPool.request().query(Q_CARGOS)).recordset;
    log(`SQL Server: ${cargosRows.length} cargos`);

    if (!DRY_RUN && cargosRows.length) {
      let cInserted = 0, cUpdated = 0;
      for (let i = 0; i < cargosRows.length; i += BATCH) {
        const batch = cargosRows.slice(i, i + BATCH);
        const params = [];
        const rowParts = batch.map(r => {
          params.push(clean(r.codigo, 30), clean(r.nombre, 120), true, AUD, NOW, NOW);
          const n = params.length;
          return `($${n-5},$${n-4},$${n-3},$${n-2},$${n-1},$${n})`;
        });
        const res = await pg.query(
          `INSERT INTO cargos (codigo, nombre, activo, aud_usuario, created_at, updated_at)
           VALUES ${rowParts.join(',')}
           ON CONFLICT (codigo) DO UPDATE SET
             nombre     = EXCLUDED.nombre,
             updated_at = EXCLUDED.updated_at`,
          params
        );
        // rowCount in ON CONFLICT DO UPDATE counts all affected rows
        cInserted += batch.length;
      }
      await pg.query(`SELECT setval('cargos_id_seq', (SELECT COALESCE(MAX(id), 1) FROM cargos))`);
      log(`Cargos upsertados: ${cargosRows.length}\n`);
    } else {
      log(`(dry-run) Se procesarían ${cargosRows.length} cargos\n`);
    }

    // ── 2. Precargar mapas de IDs desde PostgreSQL ────────────────────────────
    const [sucRes, cargoRes] = await Promise.all([
      pg.query('SELECT id, codigo FROM sucursales'),
      pg.query('SELECT id, codigo FROM cargos'),
    ]);
    const sucByCode   = Object.fromEntries(sucRes.rows.map(r => [r.codigo, r.id]));
    const cargoByCode = Object.fromEntries(cargoRes.rows.map(r => [r.codigo, r.id]));
    log(`Sucursales en RDS: ${sucRes.rows.length}`);
    log(`Cargos en RDS:     ${cargoRes.rows.length}`);

    // ── 3. Precargar empleados existentes en RDS (por codigo) ─────────────────
    const existRes = await pg.query('SELECT codigo FROM empleados');
    const existentes = new Set(existRes.rows.map(r => r.codigo));
    log(`Empleados ya en RDS: ${existentes.size}\n`);

    // ── 4. Consultar empleados SQL Server ─────────────────────────────────────
    log('=== EMPLEADOS ===');
    const empRows = (await mssqlPool.request().query(Q_EMPLEADOS)).recordset;
    log(`SQL Server: ${empRows.length} empleados (activos + inactivos)`);

    const paraInsertar = [];
    const paraActualizar = [];  // [activo, departamento_id, fecha_ingreso, cargo_id, salario_base, email, codigo]

    let sinDepMap = 0;

    for (const r of empRows) {
      const codigo        = clean(r.codigo, 20);
      const activo        = r.activo === 1 || r.activo === true;
      const departamentoId = DEP_DEPARTAMENTO_MAP[r.dep_codigo] ?? null;

      if (existentes.has(codigo)) {
        const fechaIngreso  = r.fecha_ingreso ? new Date(r.fecha_ingreso) : null;
        const cargoId       = cargoByCode[r.cargo_codigo] ?? null;
        const salarioBase   = r.salario_mes != null ? parseFloat(r.salario_mes) : null;
        const emailVal      = clean(r.email, 150);
        paraActualizar.push([activo, departamentoId, fechaIngreso, cargoId, salarioBase, emailVal, codigo]);
      } else if (activo) {
        // NUEVO y activo en SQL Server → insertar completo
        const sucCodigo  = DEP_SUCURSAL_MAP[r.dep_codigo] ?? null;
        const sucursalId = sucCodigo ? (sucByCode[sucCodigo] ?? null) : null;
        const cargoId    = cargoByCode[r.cargo_codigo] ?? null;

        if (!sucCodigo) sinDepMap++;

        paraInsertar.push([
          codigo,
          clean(r.nombres, 100),
          clean(r.apellidos, 100),
          clean(r.email, 150),
          cargoId,
          sucursalId,
          departamentoId,
          activo,
          r.fecha_ingreso ? new Date(r.fecha_ingreso) : null,
          r.salario_mes != null ? parseFloat(r.salario_mes) : null,
          AUD,
          NOW,
          NOW,
        ]);
      }
      // Nuevo + inactivo → se omite (no se inserta en RDS)
    }

    log(`  Para insertar (nuevos):          ${paraInsertar.length}`);
    log(`  Para actualizar activo (exist.): ${paraActualizar.length}`);
    if (sinDepMap) log(`  AVISO: ${sinDepMap} nuevos sin mapeo de sucursal`);

    if (!DRY_RUN) {
      // ── Actualizar activo en empleados existentes ─────────────────────────
      if (paraActualizar.length) {
        let updOk = 0;
        for (let i = 0; i < paraActualizar.length; i += BATCH) {
          const batch = paraActualizar.slice(i, i + BATCH);
          for (const [activo, departamentoId, fechaIngreso, cargoId, salarioBase, emailVal, codigo] of batch) {
            await pg.query(
              // activo: siempre sincronizado.
              // departamento_id, fecha_ingreso, salario_base, email: COALESCE (solo si aún está vacío).
              // cargo_id: siempre actualizado (refleja ascensos/cambios de puesto).
              // Protección: no reactivar si fue inactivado por desvinculación.
              `UPDATE empleados
               SET activo          = $1,
                   departamento_id = COALESCE(departamento_id, $2),
                   fecha_ingreso   = COALESCE(fecha_ingreso, $3),
                   cargo_id        = COALESCE($4, cargo_id),
                   salario_base    = COALESCE(salario_base, $5),
                   email           = COALESCE(NULLIF(email, ''), $6),
                   updated_at      = $7
               WHERE codigo = $8
                 AND NOT (
                   activo = false
                   AND $1 = true
                   AND aud_usuario IN ('sistema:desvinculacion', 'sistema:inactivar-desvinculados')
                 )`,
              [activo, departamentoId, fechaIngreso, cargoId, salarioBase, emailVal, NOW, codigo]
            );
            updOk++;
          }
          process.stdout.write(`\r  Actualizados: ${updOk}/${paraActualizar.length}  `);
        }
        console.log();
        log(`Activo actualizado en ${updOk} empleados existentes.`);
      }

      // ── Insertar empleados nuevos ─────────────────────────────────────────
      if (paraInsertar.length) {
        const cols = ['codigo', 'nombres', 'apellidos', 'email', 'cargo_id',
                      'sucursal_id', 'departamento_id', 'activo', 'fecha_ingreso', 'salario_base', 'aud_usuario', 'created_at', 'updated_at'];
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

      // ── Resumen final ─────────────────────────────────────────────────────
      const total = await pg.query('SELECT COUNT(*) FROM empleados');
      const activos = await pg.query('SELECT COUNT(*) FROM empleados WHERE activo = true');
      log('\n==========================================');
      log('RESUMEN FINAL');
      log(`  Empleados en RDS (total):  ${total.rows[0].count}`);
      log(`  Empleados activos:         ${activos.rows[0].count}`);
      log(`  Insertados esta ejecución: ${paraInsertar.length}`);
      log(`  Activo actualizado en:     ${paraActualizar.length}`);
      log('==========================================');
    } else {
      log('\n(dry-run) Sin cambios. Ejecuta sin --dry-run para aplicar.');
    }

  } finally {
    await Promise.all([mssqlPool.close(), pg.end()]).catch(() => {});
    log('Conexiones cerradas.');
  }
}

run().catch(err => {
  console.error('\nERROR:', err.message ?? err);
  process.exit(1);
});
