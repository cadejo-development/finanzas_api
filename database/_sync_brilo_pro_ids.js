/**
 * Pobla brilo_pro_id en nuestra tabla productos consultando olComun.dbo.Productos en Brilo.
 * Busca proCodigo = codigo (exacto) para cada producto de nuestra DB.
 * Uso: node database/_sync_brilo_pro_ids.js [--dry-run]
 */
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const sql = require('mssql');
const { Client } = require('pg');

const DRY_RUN = process.argv.includes('--dry-run');

const sqlConfig = {
  server: process.env.DB_HOST_ORIGEN,
  port: parseInt(process.env.DB_PORT_ORIGEN),
  database: 'olComun',
  user: process.env.DB_USERNAME_ORIGEN,
  password: process.env.DB_PASSWORD_ORIGEN,
  options: { encrypt: false, trustServerCertificate: true },
  requestTimeout: 60000,
};

async function main() {
  console.log(`\n══ SYNC brilo_pro_id → productos ${DRY_RUN ? '[DRY RUN]' : ''} ══\n`);

  const pool = await sql.connect(sqlConfig);
  const pg = new Client({
    host: process.env.DB_HOST_COMPRAS,
    port: parseInt(process.env.DB_PORT_COMPRAS || 5432),
    database: process.env.DB_DATABASE_COMPRAS,
    user: process.env.DB_USERNAME_COMPRAS,
    password: process.env.DB_PASSWORD_COMPRAS,
    ssl: { rejectUnauthorized: false },
  });
  await pg.connect();

  // 1. Obtener todos los códigos de nuestra DB
  const { rows: nuestros } = await pg.query(`
    SELECT id, codigo FROM productos
    ORDER BY codigo
  `);
  console.log(`Productos en nuestra DB: ${nuestros.length}`);

  const codigos = nuestros.map(r => r.codigo);

  // 2. Buscar proId en Brilo para esos códigos (en lotes de 500)
  const BATCH = 500;
  const briloMap = {};

  for (let i = 0; i < codigos.length; i += BATCH) {
    const lote = codigos.slice(i, i + BATCH);
    const placeholders = lote.map((_, j) => `@c${i + j}`).join(',');
    const req = pool.request();
    lote.forEach((c, j) => req.input(`c${i + j}`, sql.VarChar(100), c));
    const result = await req.query(`
      SELECT proId, proCodigo
      FROM dbo.Productos
      WHERE proCodigo IN (${placeholders})
    `);
    result.recordset.forEach(r => {
      briloMap[r.proCodigo] = r.proId;
    });
    process.stdout.write(`\r  Consultados ${Math.min(i + BATCH, codigos.length)}/${codigos.length}...`);
  }
  console.log('');

  const encontrados = Object.keys(briloMap).length;
  console.log(`Encontrados en Brilo: ${encontrados} / ${codigos.length}`);

  // 3. Actualizar nuestra DB
  let actualizados = 0;
  let sinBrilo = 0;

  for (const row of nuestros) {
    const proId = briloMap[row.codigo] ?? null;
    if (!proId) { sinBrilo++; continue; }

    if (!DRY_RUN) {
      await pg.query(
        'UPDATE productos SET brilo_pro_id = $1 WHERE id = $2 AND (brilo_pro_id IS NULL OR brilo_pro_id != $1)',
        [proId, row.id]
      );
    }
    actualizados++;
  }

  console.log(`\nResultados:`);
  console.log(`  Actualizados: ${actualizados}`);
  console.log(`  Sin match en Brilo: ${sinBrilo}`);

  if (DRY_RUN) {
    // Mostrar muestra de los primeros 20 matches
    console.log('\nMuestra (primeros 20 con match):');
    let shown = 0;
    for (const row of nuestros) {
      if (shown >= 20) break;
      const proId = briloMap[row.codigo];
      if (proId) {
        console.log(`  ${row.codigo.padEnd(20)} → proId=${proId}`);
        shown++;
      }
    }
    // Mostrar muestra sin match
    console.log('\nMuestra sin match en Brilo (primeros 10):');
    shown = 0;
    for (const row of nuestros) {
      if (shown >= 10) break;
      if (!briloMap[row.codigo]) {
        console.log(`  ${row.codigo}`);
        shown++;
      }
    }
  }

  await pool.close();
  await pg.end();
  console.log('\n✅ Sync completado');
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
