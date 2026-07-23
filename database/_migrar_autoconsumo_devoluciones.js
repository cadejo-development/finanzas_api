/**
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

 * _migrar_autoconsumo_devoluciones.js
 * Uso: node database/_migrar_autoconsumo_devoluciones.js
 */

const { Pool } = require('pg');
const pool = new Pool({
  host: process.env.DB_HOST,
  port: 5432, database: 'compras_db', user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
});

async function run() {
  const client = await pool.connect();
  try {
    await client.query('BEGIN');

    // 1. costo en ventas_productos
    const { rows: hasCosto } = await client.query(
      "SELECT 1 FROM information_schema.columns WHERE table_name='ventas_productos' AND column_name='costo'"
    );
    if (!hasCosto.length) {
      await client.query('ALTER TABLE ventas_productos ADD COLUMN costo NUMERIC(12,4)');
      console.log('✓ ventas_productos.costo agregado');
    } else { console.log('· ventas_productos.costo ya existía'); }

    // 2. tipo_orden y sub_ceco en ventas_ordenes
    for (const col of [['tipo_orden', "VARCHAR(30) DEFAULT 'normal'"], ['sub_ceco', 'VARCHAR(100)']]) {
      const { rows } = await client.query(
        `SELECT 1 FROM information_schema.columns WHERE table_name='ventas_ordenes' AND column_name='${col[0]}'`
      );
      if (!rows.length) {
        await client.query(`ALTER TABLE ventas_ordenes ADD COLUMN ${col[0]} ${col[1]}`);
        console.log(`✓ ventas_ordenes.${col[0]} agregado`);
      } else { console.log(`· ventas_ordenes.${col[0]} ya existía`); }
    }

    // 3. Cliente interno Cadejo
    const { rows: existeCadejo } = await client.query(
      "SELECT id FROM ventas_clientes WHERE LOWER(nombres) = 'cadejo brewing company' LIMIT 1"
    );
    if (!existeCadejo.length) {
      const { rows: nuevo } = await client.query(
        `INSERT INTO ventas_clientes (nombres, nom_comercial, activo, exento, plazo_credito, limite_credito)
         VALUES ('Cadejo Brewing Company', 'Cadejo (Interno)', true, true, 0, 0) RETURNING id`
      );
      console.log(`✓ Cliente interno Cadejo creado (id=${nuevo[0].id})`);
    } else { console.log(`· Cliente Cadejo ya existía (id=${existeCadejo[0].id})`); }

    // 4. ventas_devoluciones
    await client.query(`
      CREATE TABLE IF NOT EXISTS ventas_devoluciones (
        id           BIGSERIAL PRIMARY KEY,
        orden_id     BIGINT REFERENCES ventas_ordenes(id) ON DELETE SET NULL,
        cliente_id   BIGINT REFERENCES ventas_clientes(id),
        tipo         VARCHAR(30)  NOT NULL DEFAULT 'devolucion',
        estado       VARCHAR(30)  NOT NULL DEFAULT 'pendiente',
        motivo       TEXT,
        notas        TEXT,
        subtotal     NUMERIC(12,4) NOT NULL DEFAULT 0,
        total_iva    NUMERIC(12,4) NOT NULL DEFAULT 0,
        total        NUMERIC(12,4) NOT NULL DEFAULT 0,
        creado_por   VARCHAR(100),
        aprobado_por VARCHAR(100),
        aprobado_at  TIMESTAMP,
        created_at   TIMESTAMP DEFAULT NOW(),
        updated_at   TIMESTAMP DEFAULT NOW()
      )
    `);
    console.log('✓ ventas_devoluciones');

    // 5. ventas_devolucion_items
    await client.query(`
      CREATE TABLE IF NOT EXISTS ventas_devolucion_items (
        id              BIGSERIAL PRIMARY KEY,
        devolucion_id   BIGINT NOT NULL REFERENCES ventas_devoluciones(id) ON DELETE CASCADE,
        producto_id     BIGINT REFERENCES ventas_productos(id),
        nombre_producto VARCHAR(200),
        cantidad        NUMERIC(10,2) NOT NULL,
        precio_unitario NUMERIC(12,4) NOT NULL,
        exento          BOOLEAN DEFAULT false,
        subtotal        NUMERIC(12,4) NOT NULL,
        iva             NUMERIC(12,4) NOT NULL DEFAULT 0,
        total           NUMERIC(12,4) NOT NULL,
        motivo_item     TEXT,
        created_at      TIMESTAMP DEFAULT NOW()
      )
    `);
    console.log('✓ ventas_devolucion_items');

    await client.query('COMMIT');
    console.log('\n✅ Todo listo.');
  } catch (err) {
    await client.query('ROLLBACK');
    console.error('❌ Error:', err.message);
    process.exit(1);
  } finally {
    client.release();
    await pool.end();
  }
}

run();
