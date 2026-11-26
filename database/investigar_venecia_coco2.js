/**
 * ¿Por qué Brilo muestra 85 salidas de PT1002007 si el POS reporta ~60?
 * 1. Ver qué código POS (boton) generó cada venta de inventario de PT1002007
 * 2. Ver si PT1221002 consume PT1002007
 * 3. Ver el 2026-11-16 (-31) en detalle
 */
require('dotenv').config();
const sql = require('mssql');

const cfg = { user:'olimporeader',password:'olimporeader',server:'10.0.4.20',port:2033,
  database:'olInventario',options:{trustServerCertificate:true,encrypt:false,connectTimeout:15000,requestTimeout:30000}};

const PRO_ID     = 33072;   // PT1002007
const UBI_ID     = 57;      // REST. PASEO VENECIA
const FECHA_INI  = '2026-10-26';
const FECHA_FIN  = '2026-11-23';

async function main() {
  const pool = await sql.connect(cfg);

  // ─── 1. Movimientos individuales del 16/11 con su referencia ────────────
  console.log('=== 1. Movimiento del 2026-11-16 (-31) — ver referencia/nota ===');
  const r1 = await pool.request().query(`
    SELECT m.mmoId, m.mmoFecha, m.mmoNumero, m.mmoComentarios, t.tmoNombre, d.dmoCantidad
    FROM maeMovi m
    JOIN detMovi d ON d.mmoId = m.mmoId AND d.proId = ${PRO_ID} AND d.ubiId = ${UBI_ID}
    JOIN TiposMovi t ON t.tmoId = m.tmoId
    WHERE m.mmoFecha >= '2026-11-15' AND m.mmoFecha < '2026-11-17'
    ORDER BY m.mmoFecha
  `);
  if (!r1.recordset.length) {
    console.log('  Sin movimientos el 15-16 nov');
  } else {
    r1.recordset.forEach(r =>
      console.log(`  mmoId=${r.mmoId} | ${r.mmoFecha.toISOString().slice(0,16)} | ${r.tmoNombre} | qty=${r.dmoCantidad} | num=${r.mmoNumero} | coment=${r.mmoComentarios}`)
    );
  }

  // ─── 2. Buscar PT1221002 en Brilo Inventario ────────────────────────────
  console.log('\n=== 2. ¿Existe PT1221002 (AGUA DURA COCO-VENECIA) en inventario Brilo? ===');
  const proR = await pool.request().query(`
    SELECT p.proId, p.proCodigo, p.proNombre, p.proActivo
    FROM olComun.dbo.Productos p WHERE p.proCodigo IN ('PT1221002','PT1221001','PT1221003')
  `);
  if (!proR.recordset.length) {
    console.log('  NO existen en Brilo — son productos solo de POS, no de inventario');
  } else {
    proR.recordset.forEach(r => console.log(`  proId=${r.proId} | ${r.proCodigo} | ${r.proNombre} | activo=${r.proActivo}`));
    // Movimientos en inventario
    const proIds = proR.recordset.map(r => r.proId).join(',');
    const movR = await pool.request().query(`
      SELECT p.proCodigo, t.tmoNombre, t.tmoSigno, SUM(d.dmoCantidad) AS uds
      FROM maeMovi m
      JOIN detMovi d ON d.mmoId = m.mmoId
      JOIN TiposMovi t ON t.tmoId = m.tmoId
      JOIN olComun.dbo.Productos p ON p.proId = d.proId
      WHERE d.proId IN (${proIds}) AND m.mmoAnulado = 0
        AND m.mmoFecha >= '${FECHA_INI}' AND m.mmoFecha <= '${FECHA_FIN}'
      GROUP BY p.proCodigo, t.tmoNombre, t.tmoSigno
      ORDER BY p.proCodigo, t.tmoSigno
    `);
    if (!movR.recordset.length) console.log('  Sin movimientos de inventario en el período');
    else movR.recordset.forEach(r => console.log(`  ${r.proCodigo} | ${r.tmoSigno > 0 ? 'ENT' : 'SAL'} | ${r.tmoNombre} = ${r.uds} uds`));
  }

  // ─── 3. Resumen día a día con tipo de movimiento ─────────────────────────
  console.log('\n=== 3. Días con salidas de PT1002007 en Venecia (todos los tipos) ===');
  const diaR = await pool.request().query(`
    SELECT
      CAST(m.mmoFecha AS DATE) AS fecha,
      t.tmoNombre,
      t.tmoSigno,
      m.mmoAnulado,
      COUNT(*) AS movs,
      SUM(d.dmoCantidad) AS uds
    FROM maeMovi m
    JOIN detMovi d ON d.mmoId = m.mmoId AND d.proId = ${PRO_ID} AND d.ubiId = ${UBI_ID}
    JOIN TiposMovi t ON t.tmoId = m.tmoId
    WHERE m.mmoFecha >= '${FECHA_INI}' AND m.mmoFecha <= '${FECHA_FIN}'
    GROUP BY CAST(m.mmoFecha AS DATE), t.tmoNombre, t.tmoSigno, m.mmoAnulado
    ORDER BY fecha
  `);
  diaR.recordset.forEach(r => {
    const tag = r.mmoAnulado ? ' [ANULADO]' : '';
    console.log(`  ${r.fecha.toISOString().slice(0,10)} | ${r.tmoNombre}${tag} | ${r.tmoSigno > 0 ? '+' : '-'}${r.uds}`);
  });

  // ─── 4. Total ventas POS en Venecia del período (sucId de Venecia) ────────
  console.log('\n=== 4. Ventas POS Venecia: buscar sucId ===');
  const sucR = await pool.request().query(`
    SELECT s.sucId, s.sucNombre
    FROM olComun.dbo.Sucursales s
    WHERE s.sucNombre LIKE '%VENECIA%' OR s.sucNombre LIKE '%venecia%'
  `);
  sucR.recordset.forEach(r => console.log(`  sucId=${r.sucId} | ${r.sucNombre}`));
  const sucIds = sucR.recordset.map(r => r.sucId);

  if (sucIds.length) {
    console.log('\n=== 5. Ventas POS de agua dura coco en Venecia (período) ===');
    const venR = await pool.request().query(`
      SELECT
        p.proCodigo, p.proNombre,
        SUM(d.detCantidad) AS cantidad,
        SUM(d.detTotal) AS total
      FROM olRestaurante.dbo.maeCuentasRst c
      JOIN olRestaurante.dbo.detCuentasRst d ON d.cueId = c.cueId
      JOIN olComun.dbo.Productos p ON p.proId = d.proId
      WHERE c.cueSucId IN (${sucIds.join(',')})
        AND c.cueAnulada = 0
        AND c.cueFecha >= '${FECHA_INI}' AND c.cueFecha <= '${FECHA_FIN}'
        AND (p.proCodigo LIKE 'PT10020%' OR p.proCodigo LIKE 'PT12210%' OR p.proCodigo LIKE 'MDF172%')
      GROUP BY p.proCodigo, p.proNombre
      ORDER BY cantidad DESC
    `);
    let total = 0;
    venR.recordset.forEach(r => {
      console.log(`  ${r.proCodigo} | ${r.proNombre} | qty=${r.cantidad} | $${r.total}`);
      total += r.cantidad;
    });
    console.log(`  >>> POS TOTAL agua dura coco (todos): ${total} <<<`);
  }

  await pool.close();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
