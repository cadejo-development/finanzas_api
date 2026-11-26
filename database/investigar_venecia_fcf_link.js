/**
 * Linka los GUIDs FCF de los movimientos de inventario de PT1002007
 * con las cuentas del POS para ver qué se vendió en esas transacciones.
 * Si hay items distintos a agua dura coco → hay receta/subreceta.
 */
require('dotenv').config();
const sql = require('mssql');

const cfgInv = {user:'olimporeader',password:'olimporeader',server:'10.0.4.20',port:2033,
  database:'olInventario',options:{trustServerCertificate:true,encrypt:false,connectTimeout:15000,requestTimeout:30000}};

const PRO_ID    = 33072;   // PT1002007
const UBI_ID    = 57;      // REST. PASEO VENECIA
const FECHA_INI = '2026-10-26';
const FECHA_FIN  = '2026-11-23';

async function main() {
  const pool = await sql.connect(cfgInv);

  // 1. Obtener todos los GUIDs FCF de los movimientos de PT1002007
  console.log('=== 1. GUIDs FCF de los movimientos de inventario PT1002007 ===');
  const movR = await pool.request().query(`
    SELECT m.mmoId, m.mmoFecha, m.mmoComentarios, d.dmoCantidad
    FROM maeMovi m
    JOIN detMovi d ON d.mmoId = m.mmoId AND d.proId = ${PRO_ID} AND d.ubiId = ${UBI_ID}
    JOIN TiposMovi t ON t.tmoId = m.tmoId AND t.tmoSigno = -1
    WHERE m.mmoFecha >= '${FECHA_INI}' AND m.mmoFecha <= '${FECHA_FIN}'
      AND m.mmoAnulado = 0
    ORDER BY m.mmoFecha
  `);
  console.log(`  Total movimientos: ${movR.recordset.length} = ${movR.recordset.reduce((s,r)=>s+r.dmoCantidad,0)} uds`);

  // Extraer los GUIDs del comentario "FCF #GUID"
  const guids = movR.recordset
    .map(r => r.mmoComentarios?.match(/#([A-F0-9-]{36})/i)?.[1])
    .filter(Boolean);
  console.log(`  GUIDs FCF encontrados: ${guids.length}`);

  if (!guids.length) { await pool.close(); return; }

  // 2. Buscar esos GUIDs en el POS (mctrstGUIDFacturaAGen)
  console.log('\n=== 2. Qué cuentas POS tienen esos GUIDs ===');
  const guidList = guids.map(g => `'${g}'`).join(',');
  const posR = await pool.request().query(`
    SELECT
      p.proCodigo,
      p.proNombre,
      SUM(d.dctrstCantidad) AS cantidad,
      COUNT(DISTINCT c.mctrstId) AS cuentas
    FROM olRestaurante.dbo.maeCuentasRst c
    JOIN olRestaurante.dbo.detCuentasRst d ON d.mctrstId = c.mctrstId
    JOIN olComun.dbo.Productos p ON p.proId = d.proId
    WHERE c.mctrstGUIDFacturaAGen IN (${guidList})
      AND d.dctrstEliminado = 0
      AND c.mctrstEliminado = 0
    GROUP BY p.proCodigo, p.proNombre
    ORDER BY cantidad DESC
  `);
  console.log(`  ${posR.recordset.length} productos distintos:`);
  // Mostrar solo los más relevantes (top 15 por cantidad)
  posR.recordset.slice(0, 20).forEach(r =>
    console.log(`  ${r.proCodigo} | ${r.proNombre} | qty=${r.cantidad} (${r.cuentas} cuentas)`)
  );
  if (posR.recordset.length > 20) console.log(`  ... y ${posR.recordset.length - 20} más`);

  // 3. ¿Cuántos de esos invoices contienen exclusivamente agua dura vs mezcla con otros?
  console.log('\n=== 3. Desglose de coco vendido en esas cuentas FCF ===');
  const cocoR = await pool.request().query(`
    SELECT
      p.proCodigo,
      SUM(d.dctrstCantidad) AS cantidad
    FROM olRestaurante.dbo.maeCuentasRst c
    JOIN olRestaurante.dbo.detCuentasRst d ON d.mctrstId = c.mctrstId
    JOIN olComun.dbo.Productos p ON p.proId = d.proId
    WHERE c.mctrstGUIDFacturaAGen IN (${guidList})
      AND d.dctrstEliminado = 0
      AND c.mctrstEliminado = 0
      AND (p.proCodigo LIKE 'PT10020%' OR p.proCodigo LIKE 'PT12210%' OR p.proCodigo LIKE 'MDF172%')
    GROUP BY p.proCodigo
    ORDER BY cantidad DESC
  `);
  let totalFCF = 0;
  cocoR.recordset.forEach(r => {
    console.log(`  ${r.proCodigo} | qty=${r.cantidad}`);
    if (!r.proCodigo.startsWith('MDF')) totalFCF += r.cantidad;
  });
  console.log(`  >>> Coco total en estas FCFs (sin modificadores): ${totalFCF}`);
  console.log(`  >>> Movimientos de inventario PT1002007 en estas FCFs: ${movR.recordset.reduce((s,r)=>s+r.dmoCantidad,0)}`);

  await pool.close();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
