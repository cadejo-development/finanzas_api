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
  console.log('Conectado a PostgreSQL RDS (core_db)');

  await client.query(`
    CREATE TABLE IF NOT EXISTS kpi_plantillas (
      id               BIGSERIAL PRIMARY KEY,
      nombre           VARCHAR(150) NOT NULL,
      descripcion      TEXT,
      sucursal_id      BIGINT REFERENCES sucursales(id) ON DELETE SET NULL,
      unidad_medida    VARCHAR(80) NOT NULL DEFAULT 'unidades',
      monto_objetivo   NUMERIC(10,2),
      activo           BOOLEAN NOT NULL DEFAULT TRUE,
      aud_usuario      VARCHAR(100) NOT NULL DEFAULT 'sistema',
      created_at       TIMESTAMP,
      updated_at       TIMESTAMP
    );
  `);
  console.log('✓ kpi_plantillas creada (o ya existía)');

  await client.query(`
    CREATE TABLE IF NOT EXISTS kpi_plantilla_cargos (
      id                 BIGSERIAL PRIMARY KEY,
      kpi_plantilla_id   BIGINT NOT NULL REFERENCES kpi_plantillas(id) ON DELETE CASCADE,
      cargo_id           BIGINT NOT NULL REFERENCES cargos(id) ON DELETE CASCADE,
      created_at         TIMESTAMP,
      updated_at         TIMESTAMP,
      UNIQUE (kpi_plantilla_id, cargo_id)
    );
  `);
  console.log('✓ kpi_plantilla_cargos creada (o ya existía)');

  await client.query(`
    CREATE TABLE IF NOT EXISTS kpi_escala_bonificacion (
      id                 BIGSERIAL PRIMARY KEY,
      kpi_plantilla_id   BIGINT NOT NULL REFERENCES kpi_plantillas(id) ON DELETE CASCADE,
      porcentaje_desde   NUMERIC(5,2) NOT NULL,
      tipo               VARCHAR(30) NOT NULL,
      valor              NUMERIC(10,2) NOT NULL,
      orden              SMALLINT NOT NULL DEFAULT 0,
      created_at         TIMESTAMP,
      updated_at         TIMESTAMP
    );
  `);
  console.log('✓ kpi_escala_bonificacion creada (o ya existía)');

  // Registrar en migrations de Laravel para evitar conflicto futuro
  await client.query(`
    INSERT INTO migrations (migration, batch)
    SELECT '2026_07_15_100000_create_kpi_plantillas_tables', (SELECT COALESCE(MAX(batch),0)+1 FROM migrations)
    WHERE NOT EXISTS (
      SELECT 1 FROM migrations WHERE migration = '2026_07_15_100000_create_kpi_plantillas_tables'
    );
  `);
  console.log('✓ Migración registrada en tabla migrations');

  await client.end();
  console.log('\nMigración completada exitosamente.');
}

run().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
