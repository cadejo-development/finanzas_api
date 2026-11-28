/**
 * importar_empleados_historicos.js
 *
 * Importación única: todos los empleados en Brilo (SQL Server olComun.dbo.Empleados)
 * que NO existen en RDS PostgreSQL, incluyendo inactivos y eliminados (historial completo).
 *
 * Solo inserta — nunca actualiza registros que ya existen en RDS.
 * Los empleados inactivos/eliminados en Brilo se insertan con activo=false.
 *
 * Uso:
 *   node database/importar_empleados_historicos.js            → solo lectura (muestra plan)
 *   node database/importar_empleados_historicos.js --apply    → inserta en RDS
 */
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const sql      = require('mssql');
const { Pool } = require('pg');

const MSSQL_CFG = {
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_PASSWORD_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: parseInt(process.env.DB_PORT_ORIGEN) || 2033,
  database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

const PG_CFG = {
  host: process.env.DB_HOST, port: 5432,
  database: process.env.DB_DATABASE || 'core_db',
  user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
};

// depCodigo → codigo sucursal (mismo mapeo que migrate_empleados.js)
const DEP_MAP = {
  'C_Administración': 'SUC-CM', 'C_LOGISTICA': 'SUC-CM', 'C_MANTTO': 'SUC-CM',
  'C_Mercadeo':       'SUC-CM', 'C_Producción': 'SUC-CM', 'C_Produccion_R': 'SUC-CM',
  'C_Ventas':         'SUC-CM', 'DPT0001':      'SUC-CM', 'DPT0004': 'SUC-CM',
  'PROD':             'SUC-CM',
  'RT-001':   'SUC-ZR',
  'DPT0002':  'SUC-AE1', 'MAL-AE': 'SUC-AE1',
  'DPT0003':  'SUC-AE2',
  'DPT0006':  'SUC-PV',
  'DPT0007':  'SUC-SE',
  'DPT0008':  'SUC-HZ',
  'DPT0009':  'SUC-OP',
  'DTP00010': 'SUC-GU',
  'RT-003':   'SUC-LL',
};

const APPLY  = process.argv.includes('--apply');
const BATCH  = 50;
const NOW    = new Date();
const AUD    = 'importar_empleados_historicos';

const clean = (s, max = 150) =>
  !s ? null : String(s).trim().replace(/\s+/g, ' ').slice(0, max) || null;

const ts = () => new Date().toTimeString().slice(0, 8);
const log = s => console.log(`[${ts()}] ${s}`);

async function main() {
  if (!APPLY) log('Modo solo lectura — pasa --apply para insertar');

  log('Conectando a SQL Server (Brilo)...');
  const pool = await sql.connect(MSSQL_CFG);
  log('Conectando a PostgreSQL (RDS)...');
  const pg = new Pool(PG_CFG);
  await pg.query('SELECT 1');
  log('Conexiones ok.\n');

  try {
    // 1. Todos los empleados en Brilo (sin filtro de activo/eliminado)
    log('Consultando TODOS los empleados en Brilo...');
    const briloRes = await pool.request().query(`
      SELECT
        e.empCodigo     AS codigo,
        e.empNombres    AS nombres,
        e.empApellidos  AS apellidos,
        e.empEmail      AS email,
        e.empActivo     AS activo,
        e.empEliminado  AS eliminado,
        c.crgCodigo     AS cargo_codigo,
        d.depCodigo     AS dep_codigo
      FROM olComun.dbo.Empleados e WITH (NOLOCK)
      LEFT JOIN olComun.dbo.Cargos c WITH (NOLOCK) ON c.crgId = e.crgId
      LEFT JOIN olComun.dbo.Deptos d WITH (NOLOCK) ON d.depId = e.depId
      WHERE e.empCodigo IS NOT NULL
      ORDER BY e.empCodigo
    `);
    const briloEmps = briloRes.recordset;
    log(`  → ${briloEmps.length} empleados en Brilo (total, con inactivos y eliminados)`);

    // 2. Códigos que ya existen en RDS
    const existRes = await pg.query('SELECT codigo FROM empleados');
    const existing = new Set(existRes.rows.map(r => r.codigo));
    log(`  → ${existing.size} empleados ya en RDS`);

    // 3. Filtrar solo los que no existen en RDS
    const faltantes = briloEmps.filter(e => {
      const cod = clean(e.codigo, 20);
      return cod && !existing.has(cod);
    });
    log(`  → ${faltantes.length} empleados nuevos a importar\n`);

    if (faltantes.length === 0) {
      log('✅ No hay empleados históricos pendientes de importar.');
      return;
    }

    // 4. Cargar IDs de sucursales y cargos desde RDS
    const sucRes = await pg.query('SELECT id, codigo FROM sucursales');
    const sucByCode = {};
    sucRes.rows.forEach(r => { sucByCode[r.codigo] = r.id; });

    const cargoRes = await pg.query('SELECT id, codigo FROM cargos');
    const cargoByCode = {};
    cargoRes.rows.forEach(r => { cargoByCode[r.codigo] = r.id; });

    // 5. Mostrar plan
    let sinDepMap = 0, sinCargo = 0;
    const rows = faltantes.map(e => {
      const sucCodigo  = DEP_MAP[e.dep_codigo] ?? null;
      const sucursalId = sucCodigo ? (sucByCode[sucCodigo] ?? null) : null;
      const cargoId    = cargoByCode[e.cargo_codigo] ?? null;
      const activo     = e.activo === 1 && e.eliminado !== 1;

      if (!sucCodigo) sinDepMap++;
      if (!cargoId)  sinCargo++;

      return {
        codigo:      clean(e.codigo, 20),
        nombres:     clean(e.nombres, 120),
        apellidos:   clean(e.apellidos, 120),
        email:       clean(e.email, 120),
        cargo_id:    cargoId,
        sucursal_id: sucursalId,
        activo,
        dep_codigo:  e.dep_codigo,
        suc_codigo:  sucCodigo,
      };
    });

    // Resumen por estado
    const activos    = rows.filter(r => r.activo).length;
    const inactivos  = rows.filter(r => !r.activo).length;
    log(`=== PLAN DE IMPORTACIÓN ===`);
    log(`  Total a insertar:   ${rows.length}`);
    log(`  Activos en Brilo:   ${activos}`);
    log(`  Inactivos/Eliminados: ${inactivos}`);
    if (sinDepMap)  log(`  ⚠ Sin mapeo de depto → sucursal: ${sinDepMap}`);
    if (sinCargo)   log(`  ⚠ Sin cargo en RDS:              ${sinCargo}`);

    // Mostrar primeros 20 como muestra
    const muestra = rows.slice(0, 20);
    console.log(`\n${'Código'.padEnd(8)} ${'Nombre'.padEnd(35)} ${'Activo'.padStart(7)} ${'Suc'.padEnd(10)} ${'Cargo'.padEnd(10)}`);
    console.log('─'.repeat(80));
    muestra.forEach(r => {
      console.log(
        `${(r.codigo ?? '').padEnd(8)} ${(r.apellidos + ', ' + r.nombres).slice(0, 34).padEnd(35)} ` +
        `${(r.activo ? 'Sí' : 'No').padStart(7)} ` +
        `${(r.suc_codigo ?? r.dep_codigo ?? '?').padEnd(10)} ` +
        `${(r.cargo_id ? '✓' : '-').padEnd(10)}`
      );
    });
    if (rows.length > 20) console.log(`  ... y ${rows.length - 20} más`);

    if (!APPLY) {
      console.log(`\n⚠️  MODO SOLO LECTURA — ${rows.length} empleados pendientes. Pasa --apply para insertar.`);
      return;
    }

    // 6. Insertar en lotes
    console.log(`\n=== INSERTANDO ${rows.length} EMPLEADOS ===`);
    let ok = 0;
    for (let i = 0; i < rows.length; i += BATCH) {
      const batch = rows.slice(i, i + BATCH);
      const params = [];
      const parts  = batch.map(r => {
        params.push(
          r.codigo, r.nombres, r.apellidos, r.email,
          r.cargo_id, r.sucursal_id, r.activo, AUD, NOW, NOW
        );
        const base = params.length - 9;
        return `($${base},$${base+1},$${base+2},$${base+3},$${base+4},$${base+5},$${base+6},$${base+7},$${base+8},$${base+9})`;
      });
      await pg.query(
        `INSERT INTO empleados
           (codigo, nombres, apellidos, email, cargo_id, sucursal_id, activo, aud_usuario, created_at, updated_at)
         VALUES ${parts.join(',')}
         ON CONFLICT (codigo) DO NOTHING`,
        params
      );
      ok += batch.length;
      log(`  Lote ${i + 1}–${Math.min(i + BATCH, rows.length)}: ${batch.length} insertados`);
    }

    // 7. Verificar resultado
    const finalRes = await pg.query('SELECT COUNT(*) FROM empleados');
    log(`\n✅ Importación completa:`);
    log(`   Insertados:              ${ok}`);
    log(`   Total empleados en RDS:  ${finalRes.rows[0].count}`);

  } finally {
    await Promise.all([pool.close(), pg.end()]).catch(() => {});
    log('Conexiones cerradas.');
  }
}

main().catch(err => { console.error('ERROR FATAL:', err.message ?? err); process.exit(1); });
