require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const { Client } = require('pg');

const client = new Client({
  host: process.env.DB_HOST,
  port: 5432,
  database: 'core_db',
  user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
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
