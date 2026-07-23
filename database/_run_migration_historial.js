/**
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

 * Ejecuta la migración plaza_historial manualmente via SQL
 * node database/_run_migration_historial.js
 */
const { Pool } = require('pg');
const pg = new Pool({
  host: process.env.DB_HOST,
  port: 5432, database: 'core_db',
  user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
});

async function main() {
  // Verificar si ya existe
  const { rows } = await pg.query(`
    SELECT to_regclass('public.plaza_historial') AS existe
  `);
  if (rows[0].existe) {
    console.log('✅ Tabla plaza_historial ya existe. Nada que hacer.');
    await pg.end(); return;
  }

  await pg.query(`
    CREATE TABLE plaza_historial (
      id             BIGSERIAL PRIMARY KEY,
      plaza_id       BIGINT NOT NULL REFERENCES plazas(id) ON DELETE CASCADE,
      empleado_id    BIGINT NOT NULL REFERENCES empleados(id) ON DELETE CASCADE,
      motivo_entrada VARCHAR(30) NOT NULL DEFAULT 'ingreso',
      fecha_inicio   DATE NOT NULL,
      fecha_fin      DATE,
      motivo_salida  VARCHAR(30),
      notas          TEXT,
      aud_usuario    VARCHAR(100),
      created_at     TIMESTAMP,
      updated_at     TIMESTAMP
    )
  `);

  await pg.query(`CREATE INDEX idx_plaza_historial_plaza    ON plaza_historial(plaza_id)`);
  await pg.query(`CREATE INDEX idx_plaza_historial_empleado ON plaza_historial(empleado_id)`);
  await pg.query(`CREATE INDEX idx_plaza_historial_vigente  ON plaza_historial(plaza_id) WHERE fecha_fin IS NULL`);

  console.log('✅ Tabla plaza_historial creada con éxito.');

  // Registrar en migrations de Laravel para que artisan no intente re-migrarla
  await pg.query(`
    INSERT INTO migrations (migration, batch)
    VALUES ('2026_07_08_100000_create_plaza_historial_table', (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations))
    ON CONFLICT DO NOTHING
  `);
  console.log('✅ Migración registrada en tabla migrations.');

  await pg.end();
}

main().catch(e => { console.error('Error:', e.message); process.exit(1); });
