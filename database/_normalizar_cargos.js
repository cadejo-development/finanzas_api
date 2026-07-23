require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

/**
 * Normaliza los nombres de cargos a Title Case con preposiciones en minúscula.
 * Solo actualiza los registros que cambian.
 *
 * node database/_normalizar_cargos.js
 */
const { Pool } = require('pg');

const db = new Pool({
  host: process.env.DB_HOST,
  port: 5432, database: 'core_db',
  user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
});

const PREPS = new Set(['de', 'del', 'la', 'el', 'los', 'las', 'en', 'y', 'a', 'por', 'para', 'con', 'al']);

function normalizar(nombre) {
  return nombre
    .toLowerCase()
    .trim()
    .split(/\s+/)
    .map((w, i) => (i > 0 && PREPS.has(w)) ? w : w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
}

async function main() {
  const { rows } = await db.query('SELECT id, nombre FROM cargos ORDER BY nombre');
  console.log(`Total cargos: ${rows.length}\n`);

  let actualizados = 0;
  for (const c of rows) {
    const nuevo = normalizar(c.nombre);
    if (nuevo !== c.nombre) {
      await db.query('UPDATE cargos SET nombre = $1, updated_at = NOW() WHERE id = $2', [nuevo, c.id]);
      console.log(`  [${c.id}] "${c.nombre}"  →  "${nuevo}"`);
      actualizados++;
    }
  }

  console.log(`\n✓ ${actualizados} cargo(s) actualizados.`);
  await db.end();
}

main().catch(e => { console.error(e.message); process.exit(1); });
