/**
 * sync_brilo_stock_inventario.js
 * Sincroniza saldos de Brilo (SQL Server) → columna brilo_stock en compras_db.inventarios
 * por sucursal. Cada sucursal tiene una ubicación en Brilo (ubiId).
 *
 * Uso: node database/sync_brilo_stock_inventario.js [sucursal_id]
 * Ejemplo: node database/sync_brilo_stock_inventario.js 3   (La Libertad)
 */

const sql = require('mssql');
const { Pool } = require('pg');

const BRILO_CFG = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olInventario',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 30000 },
  requestTimeout: 180000,
};

const PG_CFG = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432, database: 'compras_db', user: 'cadejo_admin', password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
};

// Mapa sucursal_id (compras_db) → ubiId en Brilo
const SUCURSAL_UBI_MAP = {
  3:  48,  // RESTAURANTE LA LIBERTAD → REST. LA LIBERTAD (BOD-06)
  // Agregar más sucursales cuando se necesite
};

async function syncBriloStock(sucursalId) {
  const ubiId = SUCURSAL_UBI_MAP[sucursalId];
  if (!ubiId) {
    console.error(`❌ No hay mapeo de Brilo para sucursal_id=${sucursalId}. Agrega la entrada en SUCURSAL_UBI_MAP.`);
    process.exit(1);
  }

  const pool = await sql.connect(BRILO_CFG);
  console.log(`✓ Conectado a Brilo`);

  const pg = new Pool(PG_CFG);
  await pg.query('SELECT 1');
  console.log(`✓ Conectado a compras_db`);

  // 1. Obtener todos los productos en inventario de esa sucursal (con su código Brilo)
  const { rows: invItems } = await pg.query(`
    SELECT i.id, i.producto_id, p.codigo
    FROM inventarios i
    JOIN productos p ON p.id = i.producto_id
    WHERE i.sucursal_id = $1
  `, [sucursalId]);

  console.log(`\n📋 Productos en inventario sucursal ${sucursalId}: ${invItems.length}`);

  // 2. Consultar saldos en Brilo para esa ubicación
  const codes = invItems.map(i => i.codigo).filter(Boolean);
  if (!codes.length) { console.log('Sin productos con código. Abortando.'); return; }

  const inList = codes.map(c => `'${c.replace(/'/g, "''")}'`).join(',');
  const result = await pool.request().query(`
    SELECT
      LTRIM(RTRIM(p.proCodigo))       AS codigo,
      ISNULL(s.sliSaldoDisponible, 0) AS disponible
    FROM olComun.dbo.Productos p WITH (NOLOCK)
    LEFT JOIN olInventario.dbo.Saldos s WITH (NOLOCK)
      ON s.proId = p.proId AND s.ubiId = ${ubiId}
    WHERE LTRIM(RTRIM(p.proCodigo)) IN (${inList})
      AND p.proActivo = 1
  `);

  const briloMap = {};
  result.recordset.forEach(r => { briloMap[r.codigo.trim()] = r.disponible; });
  console.log(`📦 Saldos obtenidos de Brilo (ubiId=${ubiId}): ${result.recordset.length} productos`);

  // 3. Actualizar brilo_stock en compras_db
  let actualizados = 0, sinDato = 0;
  for (const item of invItems) {
    const stock = briloMap[item.codigo?.trim()];
    if (stock !== undefined) {
      await pg.query(
        `UPDATE inventarios SET brilo_stock=$1, brilo_sync_at=NOW() WHERE id=$2`,
        [stock, item.id]
      );
      actualizados++;
    } else {
      sinDato++;
    }
  }

  console.log(`\n✅ Sync completado — actualizados: ${actualizados}, sin dato en Brilo: ${sinDato}`);

  // 4. Mostrar resumen
  const { rows: resumen } = await pg.query(`
    SELECT p.codigo, p.nombre, i.brilo_stock, i.seccion
    FROM inventarios i
    JOIN productos p ON p.id = i.producto_id
    WHERE i.sucursal_id = $1 AND i.brilo_stock IS NOT NULL
    ORDER BY i.seccion, p.nombre
  `, [sucursalId]);

  console.log('\nResumen de saldos Brilo para sucursal', sucursalId, ':');
  resumen.forEach(r => {
    console.log(`  [${(r.seccion || '---').padEnd(12)}] ${r.nombre.padEnd(45)} ${r.brilo_stock}`);
  });

  await pool.close();
  await pg.end();
}

const sucursalId = parseInt(process.argv[2] || '3', 10);
console.log(`🔄 Sincronizando stock Brilo → compras_db para sucursal_id=${sucursalId}`);
syncBriloStock(sucursalId).catch(e => { console.error('❌ Error:', e.message); process.exit(1); });
