/**
 * copy_railway_to_rds.js
 * Copia TODA la data de Railway → RDS (tabla por tabla)
 * Uso: node copy_railway_to_rds.js
 */
const { Client } = require('pg');

const RDS_HOST = 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com';
const RDS_PORT = 5432;
const RDS_USER = 'cadejo_admin';
const RDS_PASS = 'Holamundo#3..';

const DATABASES = [
  {
    label: 'core_db',
    src: { host: 'centerbeam.proxy.rlwy.net', port: 42433, user: 'postgres', password: 'kOGUAWGcBiGgYWFdKXjEDmHHnDEQLAVy', database: 'railway', ssl: { rejectUnauthorized: false } },
    dst: { host: RDS_HOST, port: RDS_PORT, user: RDS_USER, password: RDS_PASS, database: 'core_db', ssl: { rejectUnauthorized: false } },
  },
  {
    label: 'compras_db',
    src: { host: 'centerbeam.proxy.rlwy.net', port: 54991, user: 'postgres', password: 'PeEZeoTayeiGpLohXdoxnJgECRyArmvw', database: 'railway', ssl: { rejectUnauthorized: false } },
    dst: { host: RDS_HOST, port: RDS_PORT, user: RDS_USER, password: RDS_PASS, database: 'compras_db', ssl: { rejectUnauthorized: false } },
  },
  {
    label: 'pagos_db',
    src: { host: 'crossover.proxy.rlwy.net', port: 18406, user: 'postgres', password: 'ojYHrFKtPwXxBPbjGsheJMncJIgUgykY', database: 'railway', ssl: { rejectUnauthorized: false } },
    dst: { host: RDS_HOST, port: RDS_PORT, user: RDS_USER, password: RDS_PASS, database: 'pagos_db', ssl: { rejectUnauthorized: false } },
  },
];

const SKIP_TABLES = ['migrations'];
const BATCH = 500;

async function getTables(client) {
  const res = await client.query(`
    SELECT tablename FROM pg_tables
    WHERE schemaname = 'public'
    ORDER BY tablename
  `);
  return res.rows.map(r => r.tablename);
}

async function getColumns(client, table) {
  const res = await client.query(`
    SELECT column_name FROM information_schema.columns
    WHERE table_schema = 'public' AND table_name = $1
    ORDER BY ordinal_position
  `, [table]);
  return res.rows.map(r => r.column_name);
}

async function copyTable(src, dst, table) {
  const cols = await getColumns(src, table);
  if (cols.length === 0) { console.log(`  [${table}] sin columnas, skip`); return 0; }

  const countRes = await src.query(`SELECT COUNT(*) FROM "${table}"`);
  const total = parseInt(countRes.rows[0].count);
  if (total === 0) { console.log(`  [${table}] vacía, skip`); return 0; }

  // Truncate destino con CASCADE para limpiar FKs
  await dst.query(`TRUNCATE TABLE "${table}" RESTART IDENTITY CASCADE`);

  const colList = cols.map(c => `"${c}"`).join(', ');
  const placeholders = cols.map((_, i) => `$${i + 1}`).join(', ');

  let offset = 0;
  let inserted = 0;

  while (offset < total) {
    const rows = await src.query(`SELECT ${colList} FROM "${table}" LIMIT ${BATCH} OFFSET ${offset}`);
    if (rows.rows.length === 0) break;

    for (const row of rows.rows) {
      const values = cols.map(c => row[c]);
      await dst.query(`INSERT INTO "${table}" (${colList}) VALUES (${placeholders}) ON CONFLICT DO NOTHING`, values);
      inserted++;
    }
    offset += rows.rows.length;
    process.stdout.write(`\r  [${table}] ${inserted}/${total}`);
  }
  console.log(`\r  [${table}] ✓ ${inserted}/${total}`);
  return inserted;
}

async function copyDatabase({ label, src, dst }) {
  console.log(`\n${'='.repeat(50)}`);
  console.log(`Copiando: ${label}`);
  console.log('='.repeat(50));

  const srcClient = new Client(src);
  const dstClient = new Client(dst);
  await srcClient.connect();
  await dstClient.connect();

  const tables = (await getTables(srcClient)).filter(t => !SKIP_TABLES.includes(t));
  console.log(`Tablas encontradas: ${tables.join(', ')}\n`);

  // Deshabilitar triggers FK en destino
  await dstClient.query('SET session_replication_role = replica');

  let totalInserted = 0;
  for (const table of tables) {
    try {
      totalInserted += await copyTable(srcClient, dstClient, table);
    } catch (e) {
      console.log(`\n  [${table}] ERROR: ${e.message}`);
    }
  }

  // Re-habilitar triggers
  await dstClient.query('SET session_replication_role = DEFAULT');

  await srcClient.end();
  await dstClient.end();
  console.log(`\n→ ${label} completado: ${totalInserted} filas copiadas`);
}

(async () => {
  for (const db of DATABASES) {
    await copyDatabase(db);
  }
  console.log('\n✓ Migración completa.');
})().catch(e => { console.error('ERROR FATAL:', e.message); process.exit(1); });
