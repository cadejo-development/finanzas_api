/**
 * Buscar qué otros productos en Brilo consumen PT1002007 en inventario.
 * Estrategia: en los movimientos de inventario de PT1002007, buscar OTROS productos
 * que están en el MISMO movimiento (mmoId). Si un item que NO es agua dura coco
 * aparece en el mismo batch que PT1002007, es porque ese item tiene PT1002007 en su receta.
 *
 * También busca días con muchos inventario pero pocos POS.
 */
require('dotenv').config();
const sql = require('mssql');

const cfgInv = {user:'olimporeader',password:'olimporeader',server:'10.0.4.20',port:2033,
  database:'olInventario',options:{trustServerCertificate:true,encrypt:false,connectTimeout:15000,requestTimeout:30000}};

const PRO_ID    = 33072;
const UBI_ID    = 57;
const AGUA_DURA_IDS = '33072,31558,31559,31560'; // PT1002007 y PT122100x

async function main() {
  const pool = await sql.connect(cfgInv);

  // 1. Ver qué otros productos están en los mismos mmoId que PT1002007 en Venecia
  //    pero que NO son agua dura (PT1002007/PT122100x)
  console.log('=== 1. Productos que comparten mmoId con PT1002007 en Venecia (no agua dura) ===');
  const r1 = await pool.request().query(`
    SELECT
      p.proCodigo,
      p.proNombre,
      COUNT(DISTINCT m.mmoId) AS movs,
      SUM(d2.dmoCantidad) AS uds
    FROM maeMovi m
    JOIN detMovi d ON d.mmoId = m.mmoId AND d.proId = ${PRO_ID} AND d.ubiId = ${UBI_ID}
    JOIN detMovi d2 ON d2.mmoId = m.mmoId AND d2.ubiId = ${UBI_ID}
    JOIN olComun.dbo.Productos p ON p.proId = d2.proId
    JOIN TiposMovi t ON t.tmoId = m.tmoId AND t.tmoSigno = -1
    WHERE m.mmoAnulado = 0
      AND m.mmoFecha >= '2026-10-26' AND m.mmoFecha <= '2026-11-23'
      AND d2.proId NOT IN (${AGUA_DURA_IDS})
    GROUP BY p.proCodigo, p.proNombre
    ORDER BY movs DESC
  `);
  if (!r1.recordset.length) {
    console.log('  Ningún otro producto comparte mmoId — PT1002007 se mueve solo');
  } else {
    r1.recordset.slice(0,20).forEach(r =>
      console.log(`  ${r.proCodigo} | ${r.proNombre} | ${r.movs} movs | ${r.uds} uds`)
    );
    if (r1.recordset.length > 20) console.log(`  ... y ${r1.recordset.length - 20} más`);
  }

  // 2. ¿Hay movimientos de SOLO PT1002007 (mmoId que solo contiene ese proId)?
  console.log('\n=== 2. Movimientos que contienen SOLO PT1002007 en Venecia ===');
  const r2 = await pool.request().query(`
    SELECT COUNT(DISTINCT m.mmoId) AS movs_solo, SUM(d.dmoCantidad) AS uds_solo
    FROM maeMovi m
    JOIN detMovi d ON d.mmoId = m.mmoId AND d.proId = ${PRO_ID} AND d.ubiId = ${UBI_ID}
    JOIN TiposMovi t ON t.tmoId = m.tmoId AND t.tmoSigno = -1
    WHERE m.mmoAnulado = 0
      AND m.mmoFecha >= '2026-10-26' AND m.mmoFecha <= '2026-11-23'
      AND NOT EXISTS (
        SELECT 1 FROM detMovi d2
        WHERE d2.mmoId = m.mmoId AND d2.proId != ${PRO_ID}
      )
  `);
  console.log(`  Movimientos que solo tienen PT1002007: ${r2.recordset[0].movs_solo} movs = ${r2.recordset[0].uds_solo} uds`);

  // 3. Ver los movimientos del 15/11 en el POS (que tenían 9 ventas pero 0 en inventario)
  //    y los del 16/11 (31 en inventario, 15 en POS)
  //    Para ver si las cuentas POS del 15 se postearon al inventario el 16
  console.log('\n=== 3. Movimientos de inventario PT1002007 en Venecia el 15/11 ===');
  const r3 = await pool.request().query(`
    SELECT m.mmoId, m.mmoFecha, d.dmoCantidad, m.mmoComentarios
    FROM maeMovi m
    JOIN detMovi d ON d.mmoId = m.mmoId AND d.proId = ${PRO_ID} AND d.ubiId = ${UBI_ID}
    JOIN TiposMovi t ON t.tmoId = m.tmoId AND t.tmoSigno = -1
    WHERE m.mmoAnulado = 0
      AND m.mmoFecha >= '2026-11-14' AND m.mmoFecha < '2026-11-15'
    ORDER BY m.mmoFecha
  `);
  if (!r3.recordset.length) {
    console.log('  Sin movimientos el 14/11');
  } else {
    r3.recordset.forEach(r => console.log(`  ${r.mmoFecha.toISOString().slice(0,16)} | qty=${r.dmoCantidad} | ${r.mmoComentarios}`));
  }

  // Mmocomentarios del 16/11 (ver si hay GUIDs del 15/11)
  console.log('\n=== 4. Muestra de los 10 mayores movimientos del 16/11 ===');
  const r4 = await pool.request().query(`
    SELECT m.mmoId, m.mmoFecha, m.mmoFecHoraPosteado, d.dmoCantidad, m.mmoComentarios
    FROM maeMovi m
    JOIN detMovi d ON d.mmoId = m.mmoId AND d.proId = ${PRO_ID} AND d.ubiId = ${UBI_ID}
    JOIN TiposMovi t ON t.tmoId = m.tmoId AND t.tmoSigno = -1
    WHERE m.mmoAnulado = 0
      AND m.mmoFecha >= '2026-11-16' AND m.mmoFecha < '2026-11-17'
    ORDER BY d.dmoCantidad DESC
  `);
  r4.recordset.forEach(r => console.log(`  mmoId=${r.mmoId} | fecha=${r.mmoFecha.toISOString().slice(0,16)} | posteado=${r.mmoFecHoraPosteado?.toISOString?.()?.slice(0,16)} | qty=${r.dmoCantidad}`));

  await pool.close();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
