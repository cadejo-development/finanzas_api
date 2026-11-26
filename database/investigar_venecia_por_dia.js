/**
 * Para cada día con movimientos de PT1002007:
 * compara unidades de inventario vs unidades vendidas en POS ese día
 */
require('dotenv').config();
const sql = require('mssql');

const cfgInv = {user:'olimporeader',password:'olimporeader',server:'10.0.4.20',port:2033,
  database:'olInventario',options:{trustServerCertificate:true,encrypt:false,connectTimeout:15000,requestTimeout:30000}};

const PRO_ID    = 33072;
const UBI_ID    = 57;
const COCO_IDS  = '33072,31559';

async function main() {
  const pool = await sql.connect(cfgInv);

  // Inventario día a día
  const invR = await pool.request().query(`
    SELECT
      CAST(m.mmoFecha AS DATE) AS fecha,
      SUM(d.dmoCantidad) AS inv_uds
    FROM maeMovi m
    JOIN detMovi d ON d.mmoId = m.mmoId AND d.proId = ${PRO_ID} AND d.ubiId = ${UBI_ID}
    JOIN TiposMovi t ON t.tmoId = m.tmoId AND t.tmoSigno = -1
    WHERE m.mmoAnulado = 0 AND m.mmoFecha >= '2026-10-26' AND m.mmoFecha <= '2026-11-23'
    GROUP BY CAST(m.mmoFecha AS DATE)
    ORDER BY fecha
  `);

  // POS día a día (todos los productos coco)
  const posR = await pool.request().query(`
    SELECT
      CAST(c.mctrstFecHora AS DATE) AS fecha,
      SUM(d.dctrstCantidad) AS pos_uds
    FROM olRestaurante.dbo.maeCuentasRst c
    JOIN olRestaurante.dbo.detCuentasRst d ON d.mctrstId = c.mctrstId
    WHERE c.sucIdOrigenSync = 10
      AND c.mctrstEliminado = 0
      AND d.dctrstEliminado = 0
      AND c.mctrstFecHora >= '2026-10-26' AND c.mctrstFecHora <= '2026-11-23'
      AND d.proId IN (${COCO_IDS})
    GROUP BY CAST(c.mctrstFecHora AS DATE)
    ORDER BY fecha
  `);

  const posMap = {};
  posR.recordset.forEach(r => { posMap[r.fecha.toISOString().slice(0,10)] = r.pos_uds; });

  console.log('Fecha      | INV | POS | DIFF');
  console.log('-----------|-----|-----|-----');
  let totInv = 0, totPos = 0;
  invR.recordset.forEach(r => {
    const f = r.fecha.toISOString().slice(0,10);
    const pos = posMap[f] || 0;
    const diff = r.inv_uds - pos;
    totInv += r.inv_uds;
    totPos += pos;
    console.log(`${f} | ${String(r.inv_uds).padStart(3)} | ${String(pos).padStart(3)} | ${diff > 0 ? '+' : ''}${diff}`);
  });
  // días con POS pero sin inventario
  posR.recordset.forEach(r => {
    const f = r.fecha.toISOString().slice(0,10);
    if (!invR.recordset.find(x => x.fecha.toISOString().slice(0,10) === f)) {
      totPos += r.pos_uds;
      console.log(`${f} |   - | ${String(r.pos_uds).padStart(3)} | -${r.pos_uds} (POS sin inventario)`);
    }
  });
  console.log(`\nTOTAL      | ${totInv} | ${totPos} | +${totInv - totPos}`);

  await pool.close();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
