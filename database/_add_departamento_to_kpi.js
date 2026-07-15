const { Client } = require('pg');

const client = new Client({
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432,
  database: 'core_db',
  user: 'cadejo_admin',
  password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
});

async function run() {
  await client.connect();
  console.log('Conectado...');

  await client.query(`
    ALTER TABLE kpi_plantillas
    ADD COLUMN IF NOT EXISTS departamento_id BIGINT REFERENCES departamentos(id) ON DELETE SET NULL;
  `);
  console.log('✓ departamento_id agregado a kpi_plantillas');

  await client.end();
  console.log('Listo.');
}

run().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
