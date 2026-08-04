/**
 * fix_conteo_zero_diff.js
 *
 * Inserta retroactivamente movimientos conteo_fisico con cantidad=0 para productos
 * de prod_seg que fueron contados con diferencia exacta 0 (contado == stock del sistema),
 * por lo que el código original hacía `continue` y no guardaba ningún registro.
 *
 * La reconstrucción del stock es EXACTA: suma los movimientos existentes antes del
 * momento en que el conteo fue aplicado (created_at del primer movimiento del conteo),
 * replicando exactamente lo que aplicarConteo habría calculado.
 *
 * Uso:
 *   node database/fix_conteo_zero_diff.js --dry-run     ← solo muestra, no escribe
 *   node database/fix_conteo_zero_diff.js               ← aplica el fix real
 */

require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const { Pool } = require('pg');

const isDryRun = process.argv.includes('--dry-run');

const PG_CFG = {
  host:     process.env.DB_HOST_COMPRAS,
  port:     Number(process.env.DB_PORT_COMPRAS) || 5432,
  database: process.env.DB_DATABASE_COMPRAS,
  user:     process.env.DB_USERNAME_COMPRAS,
  password: process.env.DB_PASSWORD_COMPRAS,
  ssl:      { rejectUnauthorized: false },
};

async function main() {
  const pool = new Pool(PG_CFG);

  console.log(`\n${'═'.repeat(72)}`);
  console.log(`FIX: conteo_fisico zero-diff retroactivo${isDryRun ? '  [DRY RUN — no se escribe]' : ''}`);
  console.log(`${'═'.repeat(72)}`);

  // ── 1. Todas las (sucursal, fecha, timestamp) donde hubo conteo aplicado ────
  //    Usamos MIN(created_at) para saber el momento exacto en que se aplicó,
  //    replicando lo que aplicarConteo habría visto en movimientos en ese instante.
  const { rows: conteoFechas } = await pool.query(`
    SELECT
      sucursal_id,
      fecha::text                AS fecha,
      MIN(created_at)::text      AS conteo_applied_at
    FROM movimientos_inventario
    WHERE tipo = 'conteo_fisico'
    GROUP BY sucursal_id, fecha
    ORDER BY sucursal_id, fecha
  `);

  console.log(`\nFechas con conteos: ${conteoFechas.length} combinaciones sucursal/fecha\n`);

  let totalFix  = 0;
  let totalSkip = 0;

  for (const { sucursal_id, fecha, conteo_applied_at } of conteoFechas) {

    // ── 2. Productos prod_seg con fila en historico (conteo_fisico IS NULL)
    //       que NO tienen movimiento conteo_fisico ese día ────────────────────
    const { rows: missing } = await pool.query(`
      SELECT
        psh.producto_id,
        pr.nombre                  AS nombre,
        pr.factor_conversion,
        i.cantidad_inicial_base,
        i.unidad,
        psh.brilo_stock,
        psh.conteo_fisico          AS conteo_actual
      FROM prod_seg_historico psh
      JOIN productos   pr ON pr.id = psh.producto_id
      JOIN inventarios i  ON i.sucursal_id = psh.sucursal_id
                          AND i.producto_id = psh.producto_id
      WHERE psh.sucursal_id     = $1
        AND psh.fecha           = $2
        AND psh.conteo_fisico   IS NULL
        AND NOT EXISTS (
          SELECT 1
          FROM movimientos_inventario m
          WHERE m.sucursal_id = psh.sucursal_id
            AND m.producto_id = psh.producto_id
            AND m.fecha       = psh.fecha
            AND m.tipo        = 'conteo_fisico'
        )
    `, [sucursal_id, fecha]);

    if (missing.length === 0) continue;

    console.log(`  suc_id=${String(sucursal_id).padEnd(3)}  fecha=${fecha}  aplicado=${conteo_applied_at.substring(0,19)}  →  ${missing.length} producto(s)`);

    for (const row of missing) {
      // ── 3. Reconstruir stock EXACTO al momento del conteo ──────────────────
      //    Suma los movimientos que existían ANTES de que se aplicara el conteo
      //    (created_at < conteo_applied_at), igual que lo que aplicarConteo
      //    habría sumado en ese momento. Sin filtro de fecha, igual que el original.
      const { rows: stockRows } = await pool.query(`
        SELECT COALESCE(SUM(m.cantidad_base), 0) AS sum_mov
        FROM movimientos_inventario m
        WHERE m.sucursal_id = $1
          AND m.producto_id = $2
          AND m.tipo       != 'carga_inicial'
          AND m.created_at  < $3
      `, [sucursal_id, row.producto_id, conteo_applied_at]);

      const sumMov       = parseFloat(stockRows[0].sum_mov);
      const stockBase    = parseFloat(row.cantidad_inicial_base) + sumMov;
      const factor       = Math.max(parseFloat(row.factor_conversion || 1), 0.0001);
      const totalContado = Math.round((stockBase / factor) * 10000) / 10000;

      if (totalContado < 0) {
        console.log(`    ⚠  pid=${row.producto_id} ${row.nombre.substring(0,30).padEnd(30)} SKIP stock negativo (${totalContado})`);
        totalSkip++;
        continue;
      }

      const briloStr = row.brilo_stock !== null ? Number(row.brilo_stock).toFixed(3) : 'n/a';
      const diffStr  = row.brilo_stock !== null
        ? (totalContado - parseFloat(row.brilo_stock)).toFixed(3)
        : 'n/a';

      console.log(`    │ pid=${String(row.producto_id).padEnd(5)} ${row.nombre.substring(0,26).padEnd(26)}  contado=${String(totalContado.toFixed(3)).padStart(8)}  brilo=${briloStr.padStart(8)}  diff=${diffStr.padStart(8)}`);

      if (!isDryRun) {
        const detalle = JSON.stringify({
          secciones:      [],
          total_contado:  totalContado,
          stock_anterior: totalContado,
          _fix:           'zero-diff-retroactivo',
        });

        // Insertar movimiento con cantidad=0 (no modifica el stock)
        await pool.query(`
          INSERT INTO movimientos_inventario
            (sucursal_id, producto_id, tipo, cantidad, unidad, cantidad_base,
             motivo, fecha, referencia_tipo, detalle, aud_usuario, created_at, updated_at)
          VALUES
            ($1, $2, 'conteo_fisico', 0, $3, 0,
             $4, $5, 'conteo', $6, 'fix_zero_diff', NOW(), NOW())
        `, [
          sucursal_id,
          row.producto_id,
          row.unidad,
          `Conteo físico — ${fecha}`,
          fecha,
          detalle,
        ]);

        // Actualizar prod_seg_historico para que la API lo muestre de inmediato
        await pool.query(`
          UPDATE prod_seg_historico
          SET
            conteo_fisico = $1,
            diferencia    = CASE WHEN brilo_stock IS NOT NULL THEN $1 - brilo_stock ELSE NULL END,
            updated_at    = NOW()
          WHERE sucursal_id = $2
            AND producto_id = $3
            AND fecha       = $4
        `, [totalContado, sucursal_id, row.producto_id, fecha]);

        totalFix++;
      } else {
        totalSkip++;
      }
    }
  }

  console.log(`\n${'═'.repeat(72)}`);
  if (isDryRun) {
    console.log(`DRY RUN: ${totalSkip} fila(s) candidatas (sin stock negativo se insertarían)`);
    console.log(`Ejecutar sin --dry-run para aplicar el fix.`);
  } else {
    console.log(`Fix aplicado: ${totalFix} movimiento(s) insertado(s) y prod_seg_historico actualizado.`);
  }
  console.log(`${'═'.repeat(72)}\n`);

  await pool.end();
}

main().catch(err => {
  console.error('Error:', err.message);
  process.exit(1);
});
