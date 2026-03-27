/**
 * migrate_rrhh_railway_to_rds.js
 * Copia todos los datos de Railway (rrhh) → RDS (rrhh_db)
 * El schema ya existe en RDS — solo se migran datos.
 *
 * Uso: node migrate_rrhh_railway_to_rds.js [--dry-run]
 */
const { Pool } = require('pg');

const srcCfg = {
  host:     'gondola.proxy.rlwy.net',
  port:     26145,
  user:     'postgres',
  password: 'VIpKerEbCXzxKMTuacTxKYPqhQpgifAC',
  database: 'railway',
  ssl: { rejectUnauthorized: false },
  connectionTimeoutMillis: 30000,
};

const dstCfg = {
  host:     'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port:     5432,
  user:     'cadejo_admin',
  password: 'Holamundo#3..',
  database: 'rrhh_db',
  ssl: { rejectUnauthorized: false },
  connectionTimeoutMillis: 30000,
};

const DRY_RUN = process.argv.includes('--dry-run');
const ts = () => new Date().toTimeString().slice(0, 8);
const log = s => console.log(`[${ts()}] ${s}`);

// Tablas a migrar (en orden que respeta FKs)
const TABLES = [
  'tipos_permiso',
  'tipos_incapacidad',
  'tipos_falta',
  'tipos_aumento_salarial',
  'motivos_desvinculacion',
  'saldos_vacaciones',
  'permisos',
  'vacaciones',
  'incapacidades',
  'amonestaciones',
  'dias_suspension',
  'desvinculaciones',
  'cambios_salariales',
  'traslados',
];

async function migrateTable(src, dst, table) {
  const { rows } = await src.query(`SELECT * FROM ${table} ORDER BY id`);
  if (rows.length === 0) {
    log(`  ${table}: 0 filas — omitido.`);
    return 0;
  }

  if (DRY_RUN) {
    log(`  ${table}: ${rows.length} filas (dry-run).`);
    return rows.length;
  }

  // Limpiar destino
  await dst.query(`TRUNCATE TABLE ${table} CASCADE`);

  // Insertar en batches
  const columns = Object.keys(rows[0]);
  const BATCH = 100;
  let inserted = 0;

  for (let i = 0; i < rows.length; i += BATCH) {
    const chunk = rows.slice(i, i + BATCH);
    const params = [];
    const rowParts = chunk.map(row => {
      const ph = columns.map(col => {
        params.push(row[col]);
        return `$${params.length}`;
      });
      return `(${ph.join(',')})`;
    });

    const sql = `INSERT INTO ${table} (${columns.map(c => `"${c}"`).join(',')}) VALUES ${rowParts.join(',')}`;
    await dst.query(sql, params);
    inserted += chunk.length;
  }

  // Resetear secuencia si existe columna id
  if (columns.includes('id')) {
    await dst.query(`
      SELECT setval(
        pg_get_serial_sequence('${table}', 'id'),
        COALESCE((SELECT MAX(id) FROM ${table}), 1)
      )
    `);
  }

  log(`  ${table}: ${inserted} filas migradas. ✓`);
  return inserted;
}

async function main() {
  log('='.repeat(55));
  log('MIGRACION Railway rrhh → RDS rrhh_db');
  log(DRY_RUN ? '*** DRY-RUN ***' : '*** MODO REAL ***');
  log('='.repeat(55));

  const srcPool = new Pool(srcCfg);
  const dstPool = new Pool(dstCfg);

  const src = await srcPool.connect();
  const dst = await dstPool.connect();
  log('Conexiones OK\n');

  let totalFilas = 0;
  const errores = [];

  for (const table of TABLES) {
    try {
      const n = await migrateTable(src, dst, table);
      totalFilas += n;
    } catch (e) {
      errores.push({ table, error: e.message });
      log(`  ${table}: ERROR → ${e.message}`);
    }
  }

  log('\n' + '='.repeat(55));
  log(`Total filas migradas: ${totalFilas}`);
  if (errores.length) {
    log(`Errores (${errores.length}):`);
    errores.forEach(e => log(`  - ${e.table}: ${e.error}`));
  } else {
    log(DRY_RUN ? 'DRY-RUN OK. Corre sin --dry-run para migrar.' : '✓ Migración completada.');
  }
  log('='.repeat(55));

  src.release(); await srcPool.end();
  dst.release(); await dstPool.end();
}

main().catch(err => {
  console.error('\n❌ ERROR:', err.message);
  process.exit(1);
});
