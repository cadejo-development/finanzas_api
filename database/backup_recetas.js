/**
 * backup_recetas.js
 *
 * Crea tablas de respaldo en compras_db antes del sync diario:
 *   recetas_bak_YYYYMMDD
 *   receta_ingredientes_bak_YYYYMMDD
 *   receta_sucursal_bak_YYYYMMDD
 *
 * Para restaurar en caso de problema:
 *   node backup_recetas.js --restore YYYYMMDD
 *
 * Para listar backups disponibles:
 *   node backup_recetas.js --list
 */

const { Client } = require('pg');

const PG_CFG = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'compras_db', user: 'cadejo_admin', password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false }, connectionTimeoutMillis: 30000,
};

const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s  => console.log(`[${ts()}] ${s}`);

const TABLAS = ['recetas', 'receta_ingredientes', 'receta_sucursal'];

function sufijoDia() {
  return new Date().toISOString().slice(0, 10).replace(/-/g, '');
}

async function backup(pg, sufijo) {
  log(`Creando backup con sufijo: ${sufijo}`);

  for (const tabla of TABLAS) {
    const bak = `${tabla}_bak_${sufijo}`;

    // Elimina backup anterior del mismo día si existe
    await pg.query(`DROP TABLE IF EXISTS ${bak}`);

    // Crea backup como copia exacta
    await pg.query(`CREATE TABLE ${bak} AS SELECT * FROM ${tabla}`);

    const { rows } = await pg.query(`SELECT COUNT(*) AS n FROM ${bak}`);
    log(`  ✓ ${bak}: ${rows[0].n} filas`);
  }

  log('\nBackup completado. Para restaurar:');
  log(`  node backup_recetas.js --restore ${sufijo}`);
}

async function restore(pg, sufijo) {
  log(`Restaurando backup ${sufijo}...`);

  for (const tabla of TABLAS) {
    const bak = `${tabla}_bak_${sufijo}`;

    // Verificar que el backup existe
    const { rows: ex } = await pg.query(`
      SELECT 1 FROM information_schema.tables
      WHERE table_schema = 'public' AND table_name = $1
    `, [bak]);

    if (!ex.length) {
      log(`  ✗ No existe ${bak} — abortando`);
      return;
    }

    const { rows: cnt } = await pg.query(`SELECT COUNT(*) AS n FROM ${bak}`);
    log(`  ${bak}: ${cnt[0].n} filas → restaurando en ${tabla}...`);

    await pg.query('BEGIN');
    try {
      await pg.query(`TRUNCATE TABLE ${tabla} RESTART IDENTITY CASCADE`);
      await pg.query(`INSERT INTO ${tabla} SELECT * FROM ${bak}`);

      // Resetear secuencia al máximo actual
      await pg.query(`
        SELECT setval(
          pg_get_serial_sequence('${tabla}', 'id'),
          COALESCE(MAX(id), 1)
        ) FROM ${tabla}
      `).catch(() => {}); // algunas tablas pueden no tener secuencia propia

      await pg.query('COMMIT');
      log(`  ✓ ${tabla} restaurada`);
    } catch (e) {
      await pg.query('ROLLBACK');
      log(`  ✗ Error restaurando ${tabla}: ${e.message}`);
      throw e;
    }
  }

  log('\n✓ Restauración completada.');
}

async function list(pg) {
  const { rows } = await pg.query(`
    SELECT table_name,
           pg_size_pretty(pg_total_relation_size(quote_ident(table_name))) AS tamaño
    FROM information_schema.tables
    WHERE table_schema = 'public'
      AND (table_name LIKE 'recetas_bak_%'
        OR table_name LIKE 'receta_ingredientes_bak_%'
        OR table_name LIKE 'receta_sucursal_bak_%')
    ORDER BY table_name
  `);

  if (!rows.length) {
    log('No hay backups disponibles.');
    return;
  }

  log('Backups disponibles:');
  rows.forEach(r => log(`  ${r.table_name.padEnd(40)} ${r.tamaño}`));

  // Agrupar por fecha
  const fechas = [...new Set(rows.map(r => r.table_name.split('_bak_')[1]))];
  log(`\nFechas: ${fechas.join(', ')}`);
  log('Para restaurar: node backup_recetas.js --restore YYYYMMDD');
}

// ── Main ──────────────────────────────────────────────────────────────────────
async function main() {
  const args    = process.argv.slice(2);
  const restore_flag = args.includes('--restore');
  const list_flag    = args.includes('--list');
  const sufijo  = restore_flag ? args[args.indexOf('--restore') + 1] : sufijoDia();

  const pg = new Client(PG_CFG);
  await pg.connect();
  log('PostgreSQL OK\n');

  try {
    if (list_flag) {
      await list(pg);
    } else if (restore_flag) {
      if (!sufijo || sufijo.startsWith('--')) {
        log('ERROR: Especifica la fecha. Ej: node backup_recetas.js --restore 20260601');
        return;
      }
      await restore(pg, sufijo);
    } else {
      await backup(pg, sufijo);
    }
  } finally {
    await pg.end();
  }
}

main().catch(err => {
  console.error('\nERROR:', err.message ?? err);
  process.exit(1);
});
