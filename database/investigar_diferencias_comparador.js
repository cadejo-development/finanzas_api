require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const { Pool } = require('pg');

const PG_CFG = {
  host: process.env.DB_HOST_COMPRAS || process.env.DB_HOST, port: 5432,
  database: process.env.DB_DATABASE_COMPRAS || 'gestion_operaciones_db',
  user: process.env.DB_USERNAME_COMPRAS || process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD_COMPRAS || process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
};

// Prefijos que el sync_recetas_diario.js procesa
const PREFIJOS_SYNC = [
  'PL', 'SUBR', 'PR', 'PRD', 'LLPL', 'HZPL', 'HZPR', 'EVPL', 'VOPL', 'PMPL',
  'PT', 'AE', 'PM', 'BR', 'CP', 'MR', 'DV',
];
const enSync = cod => cod && PREFIJOS_SYNC.some(p => String(cod).startsWith(p));

async function main() {
  const pool = new Pool(PG_CFG);
  const pg   = await pool.connect();

  try {
    console.log('═══════════════════════════════════════════════════════════════');
    console.log('INVESTIGACIÓN PROFUNDA: ¿Por qué 442 diferencias genuinas?');
    console.log('═══════════════════════════════════════════════════════════════\n');

    // 1. De las 442 mod_local=false con diferente conteo de ingredientes,
    //    ¿cuántas tienen un código que el sync diario NO procesa?
    const { rows: porPrefijo } = await pg.query(`
      SELECT
        r.codigo_origen,
        r.nombre,
        r.sincronizado_brilo,
        r.updated_at::date AS ultima_actualizacion,
        (SELECT COUNT(*) FROM receta_ingredientes WHERE receta_id = r.id) AS ing_sistema,
        (SELECT COUNT(*) FROM brilo_snapshot_ingredientes WHERE receta_codigo = b.codigo AND activo = true) AS ing_brilo
      FROM recetas r
      JOIN brilo_snapshot_recetas b ON b.codigo = r.codigo_origen
      WHERE r.modificado_localmente = false
        AND (
          (SELECT COUNT(*) FROM receta_ingredientes WHERE receta_id = r.id)
          !=
          (SELECT COUNT(*) FROM brilo_snapshot_ingredientes WHERE receta_codigo = b.codigo AND activo = true)
        )
      ORDER BY r.codigo_origen
      LIMIT 300
    `);

    // Clasificar por prefijo
    const enSyncCount     = porPrefijo.filter(r => enSync(r.codigo_origen)).length;
    const fueraSyncCount  = porPrefijo.filter(r => !enSync(r.codigo_origen)).length;

    // Agrupar por prefijo
    const prefijos = {};
    porPrefijo.forEach(r => {
      const pref = r.codigo_origen.match(/^[A-Z]+/)?.[0] ?? '???';
      if (!prefijos[pref]) prefijos[pref] = { count: 0, ejemplos: [] };
      prefijos[pref].count++;
      if (prefijos[pref].ejemplos.length < 3)
        prefijos[pref].ejemplos.push(`${r.codigo_origen} (${r.ing_sistema}→${r.ing_brilo})`);
    });

    console.log('── Clasificación de las 442 diferencias (mod_local=false) ─────');
    console.log(`  Prefijos QUE el sync procesa (PL,SUBR,PR...): ${enSyncCount}`);
    console.log(`  Prefijos FUERA del sync (AEPL,AE1,DV,MR...):  ${fueraSyncCount}`);
    console.log('\n  Desglose por prefijo de código:');
    Object.entries(prefijos)
      .sort((a, b) => b[1].count - a[1].count)
      .forEach(([pref, data]) => {
        const esSync = enSync(pref) ? '✓ en sync' : '✗ fuera del sync';
        console.log(`  ${pref.padEnd(10)} ${String(data.count).padStart(4)}  [${esSync}]`);
        data.ejemplos.forEach(e => console.log(`              ${e}`));
      });

    // 2. ¿Cuántas de las 62 BRILO inactivo / Sistema activo también son por prefijo fuera del sync?
    const { rows: inactivosPorPref } = await pg.query(`
      SELECT r.codigo_origen, r.nombre, b.activo AS brilo_activo, r.activa AS sistema_activa
      FROM recetas r
      JOIN brilo_snapshot_recetas b ON b.codigo = r.codigo_origen
      WHERE r.activa = true AND b.activo = false
      ORDER BY r.codigo_origen
    `);

    const inactivoEnSync    = inactivosPorPref.filter(r => enSync(r.codigo_origen));
    const inactivoFueraSync = inactivosPorPref.filter(r => !enSync(r.codigo_origen));
    console.log('\n── BRILO inactivo pero Sistema activo (62 registros) ───────────');
    console.log(`  Prefijos en sync (DEBERÍAN haberse desactivado):  ${inactivoEnSync.length}  ← BUG en sync`);
    console.log(`  Prefijos fuera del sync (sync no los toca):        ${inactivoFueraSync.length}  ← explicado`);

    if (inactivoEnSync.length > 0) {
      console.log('\n  Recetas que el sync debió desactivar pero no lo hizo:');
      inactivoEnSync.slice(0, 10).forEach(r =>
        console.log(`    ${r.codigo_origen.padEnd(20)} ${r.nombre.slice(0, 45)}`));
      if (inactivoEnSync.length > 10) console.log(`    ... y ${inactivoEnSync.length - 10} más`);
    }

    // 3. Recetas con prefijo en sync pero diferencias persistentes: ¿cuándo fue el último sync?
    if (enSyncCount > 0) {
      const { rows: syncFallas } = await pg.query(`
        SELECT
          r.codigo_origen, r.nombre,
          r.sincronizado_brilo,
          r.updated_at,
          (SELECT COUNT(*) FROM receta_ingredientes WHERE receta_id = r.id) AS ing_sistema,
          (SELECT COUNT(*) FROM brilo_snapshot_ingredientes WHERE receta_codigo = b.codigo AND activo = true) AS ing_brilo
        FROM recetas r
        JOIN brilo_snapshot_recetas b ON b.codigo = r.codigo_origen
        WHERE r.modificado_localmente = false
          AND (
            (SELECT COUNT(*) FROM receta_ingredientes WHERE receta_id = r.id)
            !=
            (SELECT COUNT(*) FROM brilo_snapshot_ingredientes WHERE receta_codigo = b.codigo AND activo = true)
          )
          AND (r.codigo_origen LIKE 'PL%' OR r.codigo_origen LIKE 'SUBR%'
            OR r.codigo_origen LIKE 'PR%' OR r.codigo_origen LIKE 'PRD%')
        ORDER BY r.updated_at DESC
        LIMIT 15
      `);
      console.log('\n  Recetas EN SYNC con diferencias persistentes (muestra):');
      syncFallas.forEach(r =>
        console.log(`    ${r.codigo_origen.padEnd(18)} ing_sist=${r.ing_sistema} ing_brilo=${r.ing_brilo}  updated=${String(r.updated_at).slice(0,10)}  sinc=${r.sincronizado_brilo}`));
    }

    // 4. Distribución de recetas fuera del sync por prefijo
    const { rows: fueraSync } = await pg.query(`
      SELECT
        SUBSTRING(r.codigo_origen FROM '^[A-Z]+') AS prefijo,
        COUNT(*) AS total,
        COUNT(*) FILTER (WHERE r.activa = true) AS activas,
        COUNT(*) FILTER (WHERE r.modificado_localmente = true) AS mod_local
      FROM recetas r
      WHERE r.codigo_origen IS NOT NULL
        AND r.codigo_origen !~ '^(PL|SUBR|PR|PRD|LLPL|HZPL|HZPR|EVPL|VOPL|PMPL)'
      GROUP BY prefijo
      ORDER BY total DESC
    `);
    console.log('\n── Recetas con prefijos FUERA del sync diario ──────────────────');
    console.log(`  ${'Prefijo'.padEnd(12)} ${'Total'.padStart(6)} ${'Activas'.padStart(8)} ${'ModLocal'.padStart(9)}`);
    fueraSync.forEach(r =>
      console.log(`  ${(r.prefijo||'?').padEnd(12)} ${String(r.total).padStart(6)} ${String(r.activas).padStart(8)} ${String(r.mod_local).padStart(9)}`));

    console.log('\n═══════════════════════════════════════════════════════════════');
    console.log('CONCLUSIÓN:');
    const totalFuera = fueraSync.reduce((s, r) => s + parseInt(r.total), 0);
    const activasFuera = fueraSync.reduce((s, r) => s + parseInt(r.activas), 0);
    console.log(`  ${totalFuera} recetas tienen prefijos que el sync NO procesa`);
    console.log(`  De esas, ${activasFuera} están activas en el sistema`);
    console.log(`  → El sync debería ampliar sus prefijos o usar filtro por categoría`);
    console.log('═══════════════════════════════════════════════════════════════');

  } finally {
    pg.release();
    await pool.end();
  }
}

main().catch(err => { console.error('ERROR:', err.message); process.exit(1); });
