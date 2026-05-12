const { Pool } = require('pg');
const pg = new Pool({
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432, database: 'compras_db', user: 'cadejo_admin',
  password: 'Holamundo#3..', ssl: { rejectUnauthorized: false }
});
pg.query("SELECT nombre FROM brew_ingredientes WHERE tipo='cerveza' ORDER BY nombre")
  .then(r => { r.rows.forEach(x => console.log(x.nombre)); pg.end(); })
  .catch(e => { console.error(e.message); process.exit(1); });
