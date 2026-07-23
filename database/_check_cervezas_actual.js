require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const { Pool } = require('pg');
const pg = new Pool({
  host: process.env.DB_HOST,
  port: 5432, database: 'compras_db', user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD, ssl: { rejectUnauthorized: false }
});
pg.query("SELECT nombre FROM brew_ingredientes WHERE tipo='cerveza' ORDER BY nombre")
  .then(r => { r.rows.forEach(x => console.log(x.nombre)); pg.end(); })
  .catch(e => { console.error(e.message); process.exit(1); });
