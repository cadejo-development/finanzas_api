require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const { Pool } = require('pg');
const pg = new Pool({
  host: process.env.DB_HOST, port: Number(process.env.DB_PORT) || 5432,
  database: 'core_db', user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
});

async function run() {
  // Encontrar ambos acreedores
  const { rows: fsvRows } = await pg.query(
    `SELECT id, nombre FROM acreedores WHERE nombre ILIKE '%FSV%' OR nombre ILIKE '%Fondo Social%' ORDER BY id`
  );
  console.log('Acreedores FSV encontrados:');
  fsvRows.forEach(r => console.log(`  id=${r.id} nombre="${r.nombre}"`));

  if (fsvRows.length < 2) {
    console.log('Solo hay uno, nada que mergear.');
    return pg.end();
  }

  // El que tiene nombre completo es el "bueno"
  const bueno = fsvRows.find(r => r.nombre.toLowerCase().includes('fondo')) ?? fsvRows[1];
  const malo  = fsvRows.find(r => r.id !== bueno.id);
  console.log(`\nMantener: id=${bueno.id} "${bueno.nombre}"`);
  console.log(`Eliminar: id=${malo.id}  "${malo.nombre}"`);

  // Reasignar ODD
  const { rowCount: r1 } = await pg.query(
    `UPDATE ordenes_descuento SET acreedor_id = $1 WHERE acreedor_id = $2`,
    [bueno.id, malo.id]
  );
  console.log(`\nODD reasignadas: ${r1}`);

  // Reasignar planilla_movimientos (referencia_id) en rrhh_db
  const pg2 = new Pool({
    host: process.env.DB_HOST, port: Number(process.env.DB_PORT) || 5432,
    database: 'rrhh_db', user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
    ssl: { rejectUnauthorized: false },
  });
  const { rowCount: r2 } = await pg2.query(
    `UPDATE planilla_movimientos SET referencia_id = $1 WHERE tipo = 'orden_descuento' AND referencia_id = $2`,
    [bueno.id, malo.id]
  );
  console.log(`planilla_movimientos reasignadas: ${r2}`);
  await pg2.end();

  // Eliminar duplicado
  await pg.query(`DELETE FROM acreedores WHERE id = $1`, [malo.id]);
  console.log(`Acreedor id=${malo.id} "${malo.nombre}" eliminado.`);

  await pg.end();
}
run().catch(e => { console.error(e.message); process.exit(1); });
