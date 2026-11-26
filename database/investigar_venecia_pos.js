/**
 * Ventas POS reales de agua dura coco en Venecia
 * usando los nombres de columnas correctos de olRestaurante
 */
require('dotenv').config();
const sql = require('mssql');

const cfg = {user:'olimporeader',password:'olimporeader',server:'10.0.4.20',port:2033,
  database:'olRestaurante',options:{trustServerCertificate:true,encrypt:false,connectTimeout:15000,requestTimeout:30000}};

const FECHA_INI = '2026-10-26';
const FECHA_FIN = '2026-11-23';
// proIds de agua dura coco: PT1002007 → Brilo proId=33072, PT1221002 → 31559
// MDF1725 → buscar
const CODIGOS = "('PT1002007','PT1221001','PT1221002','PT1221003','MDF1725','MDF1726','MDF1727')";

async function main() {
  const pool = await sql.connect(cfg);

  // buscar proIds de los productos de interés
  console.log('=== 1. proIds en olComun de los productos de interés ===');
  const proR = await pool.request().query(`
    SELECT p.proId, p.proCodigo, p.proNombre
    FROM olComun.dbo.Productos p
    WHERE p.proCodigo IN ${CODIGOS.replace(/'/g,"'")}
    ORDER BY p.proCodigo
  `);
  proR.recordset.forEach(r => console.log(`  proId=${r.proId} | ${r.proCodigo} | ${r.proNombre}`));
  const proIds = proR.recordset.map(r => r.proId);
  if (!proIds.length) { console.log('  ninguno'); return; }

  // Ventas POS agrupadas por producto, sucIdOrigenSync=10 (Venecia)
  console.log('\n=== 2. Ventas POS Venecia (sucId=10), no eliminados, período ===');
  const venR = await pool.request().query(`
    SELECT
      p.proCodigo,
      p.proNombre,
      SUM(d.dctrstCantidad) AS cantidad
    FROM maeCuentasRst c
    JOIN detCuentasRst d ON d.mctrstId = c.mctrstId
    JOIN olComun.dbo.Productos p ON p.proId = d.proId
    WHERE c.sucIdOrigenSync = 10
      AND c.mctrstEliminado = 0
      AND c.mctrstFecHora >= '${FECHA_INI}' AND c.mctrstFecHora <= '${FECHA_FIN}'
      AND d.proId IN (${proIds.join(',')})
      AND d.dctrstEliminado = 0
    GROUP BY p.proCodigo, p.proNombre
    ORDER BY cantidad DESC
  `);
  let total = 0;
  venR.recordset.forEach(r => {
    console.log(`  ${r.proCodigo} | ${r.proNombre} | qty=${r.cantidad}`);
    if (!r.proCodigo.startsWith('MDF')) total += r.cantidad;
  });
  console.log(`  >>> Total (excluyendo modificadores): ${total}`);

  // Qué otros productos también usan PT1002007 en inventario (recetas Brilo)
  // Para eso necesitamos ver qué productos están vendidos en los MISMOS mmoId que PT1002007
  // Lo hacemos via olInventario
  console.log('\n=== 3. Verificar si hay recetas Brilo de PT1002007 ===');
  const recetaR = await pool.request().query(`
    SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_NAME LIKE '%recet%' OR TABLE_NAME LIKE '%ingred%' OR TABLE_NAME LIKE '%formula%'
    ORDER BY TABLE_NAME
  `);
  if (!recetaR.recordset.length) {
    // buscar en olInventario
    const pool2 = await sql.connect({...cfg, database:'olInventario'});
    const r2 = await pool2.request().query(`
      SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_NAME LIKE '%recet%' OR TABLE_NAME LIKE '%ingred%' OR TABLE_NAME LIKE '%formula%'
      ORDER BY TABLE_NAME
    `);
    if (r2.recordset.length) {
      console.log('  Tablas receta en olInventario:', r2.recordset.map(x=>x.TABLE_NAME).join(', '));
    } else {
      // buscar en olComun
      const pool3 = await sql.connect({...cfg, database:'olComun'});
      const r3 = await pool3.request().query(`
        SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_NAME LIKE '%recet%' OR TABLE_NAME LIKE '%ingred%' OR TABLE_NAME LIKE '%formula%'
        OR TABLE_NAME LIKE '%Formula%' OR TABLE_NAME LIKE '%Receta%'
        ORDER BY TABLE_NAME
      `);
      console.log('  olComun:', r3.recordset.map(x=>x.TABLE_NAME).join(', ') || 'nada');
      pool3.close();
    }
    pool2.close();
  } else {
    console.log('  olRestaurante:', recetaR.recordset.map(x=>x.TABLE_NAME).join(', '));
  }

  await pool.close();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
