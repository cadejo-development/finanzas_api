/**
 * migrate_empleados.js
 *
 * SQL Server (olcomun) → PostgreSQL core_db (Railway)
 *
 * Migra:
 *   1. cargos   — desde olComun.dbo.Cargos (solo los usados por empleados activos)
 *   2. empleados — desde olComun.dbo.Empleados activos,
 *                  resolv cargo_id y sucursal_id por código
 *
 * Mapeo depCodigo → sucursal codigo (mismo que en migración PHP):
 *   DEP-01 → SUC-CM   DEP-02 → SUC-ZR   DEP-03 → SUC-SR
 *   DEP-05 → SUC-LL   DEP-06 → SUC-AE1  DEP-07 → SUC-AE2
 *   DEP-08 → SUC-SM   DEP-09 → SUC-PV   DEP-10 → SUC-SE
 *   DEP-12 → SUC-HZ   DEP-13 → SUC-OP   DEP-14 → SUC-GU
 *   DEP-15 → SUC-CO   DEP-16 → SUC-PD
 *
 * Idempotente: ON CONFLICT (codigo) DO UPDATE
 *
 * Uso:
 *   node migrate_empleados.js            (ejecuta)
 *   node migrate_empleados.js --dry-run  (solo muestra conteos, no inserta)
 *
 * REQUIERE: VPN activa hacia 10.0.4.20
 */

const sql      = require('mssql');
const { Pool } = require('pg');

// ── SQL Server ────────────────────────────────────────────────────────────────
const MSSQL_CFG = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

// ── PostgreSQL core_db ────────────────────────────────────────────────────────
const PG_CFG = {
  host: 'centerbeam.proxy.rlwy.net', port: 42433,
  database: 'railway', user: 'postgres',
  password: 'kOGUAWGcBiGgYWFdKXjEDmHHnDEQLAVy',
  ssl: { rejectUnauthorized: false },
  connectionTimeoutMillis: 30000,
  idleTimeoutMillis: 90000,
};

// ── Mapeo depCodigo → codigo sucursal ─────────────────────────────────────────
// Corporativos → CASA MATRIZ
// Restaurantes → sucursal correspondiente
const DEP_SUCURSAL_MAP = {
  // Corporativos / áreas centrales
  'C_Administración': 'SUC-CM',
  'C_LOGISTICA':      'SUC-CM',
  'C_MANTTO':         'SUC-CM',
  'C_Mercadeo':       'SUC-CM',
  'C_Producción':     'SUC-CM',
  'C_Produccion_R':   'SUC-CM',
  'C_Ventas':         'SUC-CM',
  'DPT0001':          'SUC-CM',   // Bodega central
  'DPT0004':          'SUC-CM',   // Eventual Ventas
  'PROD':             'SUC-CM',   // Eventual Producción y Bodegas

  // Restaurantes
  'RT-001':   'SUC-ZR',   // Zona Rosa
  'DPT0002':  'SUC-AE1',  // Aeropuerto 1
  'DPT0003':  'SUC-AE2',  // Aeropuerto 2
  'MAL-AE':   'SUC-AE1',  // Malcriadas AE (Aeropuerto)
  'DPT0006':  'SUC-PV',   // Paseo Venecia
  'DPT0007':  'SUC-SE',   // Santa Elena
  'DPT0008':  'SUC-HZ',   // Huizúcar
  'DPT0009':  'SUC-OP',   // Opico
  'DTP00010': 'SUC-GU',   // Guirola (typo DTP en origen)
  'RT-003':   'SUC-LL',   // La Libertad

  // Eventos externos/internos → sin sucursal fija (se deja NULL)
  // 'EVE-EXT': null
  // 'EVE-INT': null
  // '6': null  (Eventual Mercadeo)
};

const DRY_RUN = process.argv.includes('--dry-run');
const BATCH   = 50;
const NOW     = new Date();
const AUD     = 'migrate_empleados.js';

const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s => console.log(`[${ts()}] ${s}`);
const clean = (s, max = 150) =>
  !s ? null : String(s).trim().replace(/\s+/g, ' ').slice(0, max) || null;

// ── SQL Server queries ────────────────────────────────────────────────────────
const Q_CARGOS = `
SELECT DISTINCT
  c.crgCodigo AS codigo,
  c.crgNombre AS nombre
FROM olComun.dbo.Cargos c WITH (NOLOCK)
INNER JOIN olComun.dbo.Empleados e WITH (NOLOCK) ON e.crgId = c.crgId
WHERE e.empActivo = 1
  AND c.crgCodigo IS NOT NULL
ORDER BY c.crgCodigo
`;

const Q_EMPLEADOS = `
SELECT
  e.empCodigo     AS codigo,
  e.empNombres    AS nombres,
  e.empApellidos  AS apellidos,
  e.empEmail      AS email,
  c.crgCodigo     AS cargo_codigo,
  d.depCodigo     AS dep_codigo
FROM olComun.dbo.Empleados e WITH (NOLOCK)
INNER JOIN olComun.dbo.Cargos c WITH (NOLOCK) ON c.crgId = e.crgId
INNER JOIN olComun.dbo.Deptos d WITH (NOLOCK) ON d.depId = e.depId
WHERE e.empActivo = 1
  AND e.empCodigo IS NOT NULL
ORDER BY e.empCodigo
`;

// ── Helpers ───────────────────────────────────────────────────────────────────
async function insertBatch(pg, table, columns, rows, conflictSql) {
  if (rows.length === 0) return;
  const params  = [];
  const rowParts = rows.map(row => {
    const ph = row.map(v => { params.push(v); return `$${params.length}`; });
    return `(${ph.join(',')})`;
  });
  const query = `INSERT INTO ${table} (${columns.join(',')}) VALUES ${rowParts.join(',')} ${conflictSql}`;
  await pg.query(query, params);
}

// ── Main ──────────────────────────────────────────────────────────────────────
async function run() {
  if (DRY_RUN) log('Modo --dry-run: sin cambios en BD');

  log('Conectando a SQL Server...');
  const pool = await sql.connect(MSSQL_CFG);
  log('SQL Server OK.');

  log('Conectando a PostgreSQL (con reintentos)...');
  let pg;
  for (let attempt = 1; attempt <= 8; attempt++) {
    const p = new Pool(PG_CFG);
    try {
      await p.query('SELECT 1');
      pg = p;
      break;
    } catch (e) {
      await p.end().catch(() => {});
      if (attempt === 8) throw new Error(`PostgreSQL no disponible tras 8 intentos: ${e.message}`);
      log(`  PG intento ${attempt} fallido (${e.message}) — reintentando en 10s...`);
      await new Promise(r => setTimeout(r, 10000));
    }
  }
  log('Conectado.\n');

  try {
    // ── 1. Cargos ─────────────────────────────────────────────────────────────
    log('Consultando cargos en SQL Server...');
    const cargosResult = await pool.request().query(Q_CARGOS);
    const cargosRows   = cargosResult.recordset;
    log(`  → ${cargosRows.length} cargos encontrados`);

    if (!DRY_RUN) {
      for (let i = 0; i < cargosRows.length; i += BATCH) {
        const batch = cargosRows.slice(i, i + BATCH).map(r => [
          clean(r.codigo, 50),
          clean(r.nombre, 150),
          true,
          AUD,
          NOW,
          NOW,
        ]);
        await insertBatch(
          pg,
          'cargos',
          ['codigo', 'nombre', 'activo', 'aud_usuario', 'created_at', 'updated_at'],
          batch,
          'ON CONFLICT (codigo) DO UPDATE SET nombre = EXCLUDED.nombre, updated_at = EXCLUDED.updated_at',
        );
        log(`  → cargos: lote ${i + 1}–${Math.min(i + BATCH, cargosRows.length)} insertado/actualizado`);
      }
    }
    log(`Cargos sincronizados: ${cargosRows.length}\n`);

    // ── 2. Precargar IDs de sucursales ────────────────────────────────────────
    log('Cargando IDs de sucursales desde PostgreSQL...');
    const sucRes    = await pg.query('SELECT id, codigo FROM sucursales');
    const sucursalByCode = {};
    for (const row of sucRes.rows) sucursalByCode[row.codigo] = row.id;
    log(`  → ${sucRes.rows.length} sucursales cargadas`);

    // Verificar que el mapeo cubre todo
    const unmappedDeps = [];
    for (const [dep, suc] of Object.entries(DEP_SUCURSAL_MAP)) {
      if (!sucursalByCode[suc]) unmappedDeps.push(`${dep} → ${suc} (NO ENCONTRADO)`);
    }
    if (unmappedDeps.length) {
      log('ADVERTENCIA: sucursales no encontradas en BD:');
      unmappedDeps.forEach(m => log('  ! ' + m));
    }

    // ── 3. Precargar IDs de cargos ────────────────────────────────────────────
    log('Cargando IDs de cargos desde PostgreSQL...');
    const cargoRes = await pg.query('SELECT id, codigo FROM cargos');
    const cargoByCode = {};
    for (const row of cargoRes.rows) cargoByCode[row.codigo] = row.id;
    log(`  → ${cargoRes.rows.length} cargos en PostgreSQL`);

    // ── 4. Empleados ──────────────────────────────────────────────────────────
    log('\nConsultando empleados en SQL Server...');
    const empResult = await pool.request().query(Q_EMPLEADOS);
    const empRows   = empResult.recordset;
    log(`  → ${empRows.length} empleados encontrados`);

    let sinCargo = 0, sinSucursal = 0, sinDepMap = 0;
    const empData = empRows.map(r => {
      const cargoId    = cargoByCode[r.cargo_codigo] ?? null;
      const sucCodigo  = DEP_SUCURSAL_MAP[r.dep_codigo] ?? null;
      const sucursalId = sucCodigo ? (sucursalByCode[sucCodigo] ?? null) : null;

      if (!cargoId)    sinCargo++;
      if (!sucCodigo)  sinDepMap++;
      if (!sucursalId) sinSucursal++;

      return [
        clean(r.codigo, 50),
        clean(r.nombres, 100),
        clean(r.apellidos, 100),
        clean(r.email, 150),
        cargoId,
        sucursalId,
        true,
        AUD,
        NOW,
        NOW,
      ];
    });

    if (sinDepMap)   log(`  AVISO: ${sinDepMap} empleados con depCodigo sin mapeo a sucursal`);
    if (sinCargo)    log(`  AVISO: ${sinCargo} empleados sin cargo resuelto`);
    if (sinSucursal) log(`  AVISO: ${sinSucursal} empleados sin sucursal resuelta`);

    if (!DRY_RUN) {
      for (let i = 0; i < empData.length; i += BATCH) {
        const batch = empData.slice(i, i + BATCH);
        await insertBatch(
          pg,
          'empleados',
          ['codigo', 'nombres', 'apellidos', 'email', 'cargo_id', 'sucursal_id',
           'activo', 'aud_usuario', 'created_at', 'updated_at'],
          batch,
          `ON CONFLICT (codigo) DO UPDATE SET
            nombres      = EXCLUDED.nombres,
            apellidos    = EXCLUDED.apellidos,
            email        = EXCLUDED.email,
            cargo_id     = EXCLUDED.cargo_id,
            sucursal_id  = EXCLUDED.sucursal_id,
            updated_at   = EXCLUDED.updated_at`,
        );
        log(`  → empleados: lote ${i + 1}–${Math.min(i + BATCH, empData.length)} insertado/actualizado`);
      }
    }
    log(`\nEmpleados sincronizados: ${empData.length}`);

    // ── Resumen ───────────────────────────────────────────────────────────────
    if (!DRY_RUN) {
      const [cRes, eRes] = await Promise.all([
        pg.query('SELECT COUNT(*) FROM cargos'),
        pg.query('SELECT COUNT(*) FROM empleados'),
      ]);
      log(`\nResumen final:`);
      log(`  cargos:    ${cRes.rows[0].count}`);
      log(`  empleados: ${eRes.rows[0].count}`);
    }

  } finally {
    await Promise.all([pool.close(), pg.end()]).catch(() => {});
    log('Conexiones cerradas.');
  }
}

run().catch(err => {
  console.error('ERROR:', err.message ?? err);
  process.exit(1);
});
