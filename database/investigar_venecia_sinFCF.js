/**
 * ¿Existen ventas de agua dura coco en Venecia SIN factura fiscal?
 * El reporte DF solo muestra FCF. Si hay consumo sin factura, no aparecería.
 */
require('dotenv').config();
const sql = require('mssql');

const cfgRst = {user:'olimporeader',password:'olimporeader',server:'10.0.4.20',port:2033,
  database:'olRestaurante',options:{trustServerCertificate:true,encrypt:false,connectTimeout:15000,requestTimeout:30000}};

const FECHA_INI = '2026-10-26';
const FECHA_FIN  = '2026-11-23';
const COCO_IDS   = '33072,31559';  // PT1002007, PT1221002

async function main() {
  const pool = await sql.connect(cfgRst);

  // 1. Ventas CON factura vs SIN factura de agua dura coco
  console.log('=== 1. Ventas coco Venecia con vs sin factura fiscal ===');
  const r1 = await pool.request().query(`
    SELECT
      CASE WHEN c.mctrstGUIDFacturaAGen IS NOT NULL AND c.mctrstGUIDFacturaAGen != '' THEN 'CON_FCF' ELSE 'SIN_FCF' END AS tipo,
      p.proCodigo,
      SUM(d.dctrstCantidad) AS cantidad
    FROM maeCuentasRst c
    JOIN detCuentasRst d ON d.mctrstId = c.mctrstId
    JOIN olComun.dbo.Productos p ON p.proId = d.proId
    WHERE c.sucIdOrigenSync = 10
      AND c.mctrstEliminado = 0
      AND d.dctrstEliminado = 0
      AND c.mctrstFecHora >= '${FECHA_INI}' AND c.mctrstFecHora <= '${FECHA_FIN}'
      AND d.proId IN (${COCO_IDS})
    GROUP BY
      CASE WHEN c.mctrstGUIDFacturaAGen IS NOT NULL AND c.mctrstGUIDFacturaAGen != '' THEN 'CON_FCF' ELSE 'SIN_FCF' END,
      p.proCodigo
    ORDER BY tipo, cantidad DESC
  `);
  r1.recordset.forEach(r => console.log(`  ${r.tipo} | ${r.proCodigo} | qty=${r.cantidad}`));

  // 2. Ver qué tipo de doc generan esas ventas (mctrsTipoMoviGenero)
  console.log('\n=== 2. Tipos de movimiento/doc que generan las ventas de coco ===');
  const r2 = await pool.request().query(`
    SELECT
      c.mctrsTipoMoviGenero,
      c.mctrsTipoDocGenero,
      p.proCodigo,
      SUM(d.dctrstCantidad) AS cantidad,
      COUNT(DISTINCT c.mctrstId) AS cuentas
    FROM maeCuentasRst c
    JOIN detCuentasRst d ON d.mctrstId = c.mctrstId
    JOIN olComun.dbo.Productos p ON p.proId = d.proId
    WHERE c.sucIdOrigenSync = 10
      AND c.mctrstEliminado = 0
      AND d.dctrstEliminado = 0
      AND c.mctrstFecHora >= '${FECHA_INI}' AND c.mctrstFecHora <= '${FECHA_FIN}'
      AND d.proId IN (${COCO_IDS})
    GROUP BY c.mctrsTipoMoviGenero, c.mctrsTipoDocGenero, p.proCodigo
    ORDER BY cantidad DESC
  `);
  r2.recordset.forEach(r => console.log(`  tipoMovi=${r.mctrsTipoMoviGenero} tipoDoc=${r.mctrsTipoDocGenero} | ${r.proCodigo} | qty=${r.cantidad} (${r.cuentas} cuentas)`));

  // 3. Ver los GUID FCF de los movimientos de inventario de PT1002007
  //    para ver cuántos tienen match en el POS
  console.log('\n=== 3. Total ventas POS coco incluyendo cuentas no eliminadas (todos los estados) ===');
  const r3 = await pool.request().query(`
    SELECT
      p.proCodigo,
      SUM(d.dctrstCantidad) AS total_bruto,
      SUM(CASE WHEN d.dctrstEliminado=0 THEN d.dctrstCantidad ELSE 0 END) AS no_eliminado,
      SUM(CASE WHEN d.dctrstEliminado=1 THEN d.dctrstCantidad ELSE 0 END) AS eliminado
    FROM maeCuentasRst c
    JOIN detCuentasRst d ON d.mctrstId = c.mctrstId
    JOIN olComun.dbo.Productos p ON p.proId = d.proId
    WHERE c.sucIdOrigenSync = 10
      AND c.mctrstEliminado = 0
      AND c.mctrstFecHora >= '${FECHA_INI}' AND c.mctrstFecHora <= '${FECHA_FIN}'
      AND d.proId IN (${COCO_IDS})
    GROUP BY p.proCodigo
    ORDER BY total_bruto DESC
  `);
  r3.recordset.forEach(r => console.log(`  ${r.proCodigo} | bruto=${r.total_bruto} | no_eliminado=${r.no_eliminado} | eliminado=${r.eliminado}`));

  await pool.close();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
