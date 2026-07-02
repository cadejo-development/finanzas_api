/**
 * sync_ventas_catalogos.js
 * Extrae clientes y productos de Brilo (SQL Server) → compras_db (PostgreSQL)
 * Uso: node database/sync_ventas_catalogos.js
 */

const sql = require('mssql');
const { Pool } = require('pg');

const BRILO_CFG = {
  user: 'olimporeader',
  password: 'olimporeader',
  server: '10.0.4.20',
  port: 2033,
  database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 30000 },
  requestTimeout: 180000,
};

const PG_CFG = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432,
  database: 'compras_db',
  user: 'cadejo_admin',
  password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
  keepAlive: true,
  idleTimeoutMillis: 600000,
};

// Bodegas de productos terminados Cadejo
const BODEGAS_IDS = [59, 60, 62]; // PROD TERM CADEJO, PROD TERM SIVAR, PROMOCIONALES

async function syncClientes(pool, pg) {
  console.log('\n📥 Sincronizando clientes desde Brilo...');

  const result = await pool.request().query(`
    SELECT
      c.cliId           AS brilo_id,
      LTRIM(RTRIM(c.cliNombres))     AS nombres,
      LTRIM(RTRIM(c.cliNomComercial)) AS nom_comercial,
      LTRIM(RTRIM(c.cliNit))         AS nit,
      LTRIM(RTRIM(c.cliRegistroIva)) AS registro_iva,
      LTRIM(RTRIM(c.cliEmail))       AS email,
      LTRIM(RTRIM(c.cliTelefono))    AS telefono,
      LTRIM(RTRIM(c.cliDireccion))   AS direccion,
      c.cliExento        AS exento,
      ISNULL(c.cliPlazoCredito, 0)   AS plazo_credito,
      ISNULL(c.cliLimiteCredito, 0)  AS limite_credito,
      c.cliStatus        AS brilo_status,
      c.vndId            AS brilo_vendedor_id
    FROM dbo.Clientes c WITH (NOLOCK)
    WHERE c.cliStatus IN (0, 2)
    ORDER BY c.cliNombres
  `);

  const clientes = result.recordset;
  console.log(`   Encontrados: ${clientes.length} clientes`);

  // Batch upsert en chunks de 500
  const BATCH = 500;
  let total = 0;
  for (let i = 0; i < clientes.length; i += BATCH) {
    const chunk = clientes.slice(i, i + BATCH);
    const vals  = [];
    const rows  = chunk.map((c, j) => {
      const base = j * 13;
      vals.push(
        c.brilo_id, c.nombres, c.nom_comercial, c.nit, c.registro_iva,
        c.email, c.telefono, c.direccion, c.exento,
        c.plazo_credito, c.limite_credito, c.brilo_status, c.brilo_vendedor_id
      );
      return `($${base+1},$${base+2},$${base+3},$${base+4},$${base+5},$${base+6},$${base+7},$${base+8},$${base+9},$${base+10},$${base+11},$${base+12},$${base+13},true,NOW(),NOW())`;
    });

    await pg.query(`
      INSERT INTO ventas_clientes
        (brilo_id,nombres,nom_comercial,nit,registro_iva,email,telefono,
         direccion,exento,plazo_credito,limite_credito,brilo_status,brilo_vendedor_id,activo,created_at,updated_at)
      VALUES ${rows.join(',')}
      ON CONFLICT (brilo_id) DO UPDATE SET
        nombres=EXCLUDED.nombres, nom_comercial=EXCLUDED.nom_comercial,
        nit=EXCLUDED.nit, registro_iva=EXCLUDED.registro_iva,
        email=EXCLUDED.email, telefono=EXCLUDED.telefono, direccion=EXCLUDED.direccion,
        exento=EXCLUDED.exento, plazo_credito=EXCLUDED.plazo_credito,
        limite_credito=EXCLUDED.limite_credito, brilo_status=EXCLUDED.brilo_status,
        brilo_vendedor_id=EXCLUDED.brilo_vendedor_id, updated_at=NOW()
    `, vals);

    total += chunk.length;
    process.stdout.write(`\r   Procesados: ${total}/${clientes.length}`);
  }
  console.log(`\n   ✓ Clientes — upserted: ${total}`);
}

async function syncProductos(pool, pg) {
  console.log('\n📥 Sincronizando productos desde Brilo (bodegas 59, 60, 62)...');

  // Usar olInventario para saldos — cambiar DB en la misma conexión
  const result = await pool.request().query(`
    SELECT
      p.proId      AS brilo_id,
      LTRIM(RTRIM(p.proCodigo))  AS codigo,
      LTRIM(RTRIM(p.proNombre))  AS nombre,
      p.proExento  AS exento,
      p.proPrecio  AS precio,
      SUM(s.sliSaldoDisponible) AS existencias,
      s.ubiId      AS bodega_id,
      LTRIM(RTRIM(u.ubiNombre)) AS bodega
    FROM olInventario.dbo.Saldos s WITH (NOLOCK)
    JOIN olComun.dbo.Productos p WITH (NOLOCK) ON s.proId = p.proId
    JOIN olInventario.dbo.Ubicaciones u WITH (NOLOCK) ON s.ubiId = u.ubiId
    WHERE p.proActivo = 1
      AND s.ubiId IN (${BODEGAS_IDS.join(',')})
      AND p.proEliminado = 0
    GROUP BY p.proId, p.proCodigo, p.proNombre, p.proExento, p.proPrecio, s.ubiId, u.ubiNombre
    HAVING SUM(s.sliSaldoDisponible) >= 0
    ORDER BY u.ubiNombre, p.proNombre
  `);

  const productos = result.recordset;
  console.log(`   Encontrados: ${productos.length} registros de productos`);

  // Agrupar por brilo_id sumando existencias de todas las bodegas
  const porBrilo = new Map();
  for (const p of productos) {
    if (porBrilo.has(p.brilo_id)) {
      porBrilo.get(p.brilo_id).existencias += p.existencias;
    } else {
      porBrilo.set(p.brilo_id, { ...p });
    }
  }
  const agrupados = [...porBrilo.values()];

  // Upsert (no truncate) para preservar referencias en catálogos de precios y órdenes
  let upserted = 0;
  const briloIds = [];
  for (const p of agrupados) {
    await pg.query(`
      INSERT INTO ventas_productos
        (brilo_id, codigo, nombre, exento, precio, existencias, bodega, bodega_id, activo, created_at, updated_at)
      VALUES ($1,$2,$3,$4,$5,$6,$7,$8,true,NOW(),NOW())
      ON CONFLICT (brilo_id) DO UPDATE SET
        codigo=EXCLUDED.codigo, nombre=EXCLUDED.nombre, exento=EXCLUDED.exento,
        precio=EXCLUDED.precio, existencias=EXCLUDED.existencias,
        bodega=EXCLUDED.bodega, bodega_id=EXCLUDED.bodega_id,
        activo=true, updated_at=NOW()
    `, [p.brilo_id, p.codigo, p.nombre, p.exento, p.precio,
        p.existencias, p.bodega, p.bodega_id]);
    briloIds.push(p.brilo_id);
    upserted++;
  }

  // Desactivar productos que ya no aparecen en Brilo (preserva FKs en lugar de borrar)
  if (briloIds.length) {
    const placeholders = briloIds.map((_, i) => `$${i + 1}`).join(',');
    await pg.query(`
      UPDATE ventas_productos SET activo=false, updated_at=NOW()
      WHERE brilo_id IS NOT NULL AND brilo_id NOT IN (${placeholders}) AND activo=true
    `, briloIds);
  }

  console.log(`   ✓ Productos — upserted: ${upserted} (agrupados de ${productos.length} registros)`);
}

async function main() {
  console.log('🔄 Iniciando sync de catálogos Brilo → compras_db');
  console.log('================================================');

  const pool = await sql.connect(BRILO_CFG);
  console.log('✓ Conectado a Brilo (SQL Server)');

  const pg = new Pool(PG_CFG);
  await pg.query('SELECT 1');
  console.log('✓ Conectado a compras_db (PostgreSQL)');

  try {
    await syncClientes(pool, pg);
    await syncProductos(pool, pg);
    console.log('\n✅ Sync completado exitosamente');
  } catch (e) {
    console.error('\n❌ Error durante sync:', e.message);
    process.exit(1);
  } finally {
    await pool.close();
    await pg.end();
  }
}

main();
