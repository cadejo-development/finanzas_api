require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const { Pool } = require('pg');
const pg = new Pool({
  host: process.env.DB_HOST,
  port: 5432, database: 'compras_db', user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD, ssl: { rejectUnauthorized: false },
});
async function main() {
  const r = await pg.query(`SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_name LIKE '%modific%'`);
  console.log('Tablas con "modific":', r.rows.map(x => x.table_name));
  if (r.rows.length) {
    const c = await pg.query(`SELECT column_name FROM information_schema.columns WHERE table_name='receta_modificadores' ORDER BY ordinal_position`);
    console.log('Columnas:', c.rows.map(x => x.column_name).join(', '));
    const cnt = await pg.query(`SELECT COUNT(*) FROM receta_modificadores`);
    console.log('Filas:', cnt.rows[0].count);
  }
  await pg.end();
}
main().catch(e => console.error('ERROR:', e.message));
