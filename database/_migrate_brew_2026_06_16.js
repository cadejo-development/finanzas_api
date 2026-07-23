/**
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

 * Migración brew recetas 2026-06-16:
 *  - brew_receta_maltas: renombrar cantidad_kg → cantidad_lb, quitar lovibond
 *  - brew_recetas: quitar cajas_objetivo y barriles_objetivo,
 *                  cambiar og/fg a decimal(5,2) para grados Plato
 */
const { Pool } = require('pg');
const pg = new Pool({
  host: process.env.DB_HOST,
  port: 5432, database: 'compras_db', user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD, ssl: { rejectUnauthorized: false },
});

async function colExists(table, col) {
  const { rows } = await pg.query(
    `SELECT 1 FROM information_schema.columns
     WHERE table_schema='public' AND table_name=$1 AND column_name=$2`,
    [table, col]
  );
  return rows.length > 0;
}

async function run() {
  console.log('\n[1/5] brew_receta_maltas: cantidad_kg → cantidad_lb');
  if (await colExists('brew_receta_maltas', 'cantidad_kg')) {
    await pg.query(`ALTER TABLE brew_receta_maltas RENAME COLUMN cantidad_kg TO cantidad_lb`);
    console.log('  Renombrada.');
  } else if (await colExists('brew_receta_maltas', 'cantidad_lb')) {
    console.log('  Ya existe cantidad_lb — sin cambios.');
  } else {
    console.log('  Columna no encontrada.');
  }

  console.log('\n[2/5] brew_receta_maltas: DROP lovibond');
  if (await colExists('brew_receta_maltas', 'lovibond')) {
    await pg.query(`ALTER TABLE brew_receta_maltas DROP COLUMN lovibond`);
    console.log('  Eliminada.');
  } else {
    console.log('  Ya no existe — sin cambios.');
  }

  console.log('\n[3/5] brew_recetas: DROP cajas_objetivo, barriles_objetivo');
  for (const col of ['cajas_objetivo', 'barriles_objetivo']) {
    if (await colExists('brew_recetas', col)) {
      await pg.query(`ALTER TABLE brew_recetas DROP COLUMN ${col}`);
      console.log(`  ${col} eliminada.`);
    } else {
      console.log(`  ${col} ya no existe.`);
    }
  }

  console.log('\n[4/5] brew_recetas: og/fg → decimal(5,2) para grados Plato');
  const { rows: recetas } = await pg.query(
    `SELECT COUNT(*) as n FROM brew_recetas WHERE og IS NOT NULL OR fg IS NOT NULL`
  );
  if (parseInt(recetas[0].n) > 0) {
    console.log(`  Anulando ${recetas[0].n} recetas con og/fg en SG (unidad cambia a °P)...`);
    await pg.query(`UPDATE brew_recetas SET og = NULL, fg = NULL`);
  }
  await pg.query(`ALTER TABLE brew_recetas ALTER COLUMN og TYPE decimal(5,2)`);
  await pg.query(`ALTER TABLE brew_recetas ALTER COLUMN fg TYPE decimal(5,2)`);
  console.log('  Tipo cambiado a decimal(5,2).');

  console.log('\n[5/5] Verificación final...');
  const { rows: cols } = await pg.query(`
    SELECT column_name, data_type, numeric_precision, numeric_scale
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name IN ('brew_recetas', 'brew_receta_maltas')
      AND column_name IN ('cantidad_lb','cantidad_kg','lovibond','og','fg','cajas_objetivo','barriles_objetivo')
    ORDER BY table_name, column_name
  `);
  console.table(cols);

  console.log('\n✓ Migración completada.');
  await pg.end();
}

run().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
