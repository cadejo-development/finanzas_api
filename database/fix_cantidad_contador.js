const { Client } = require('pg')

const client = new Client({
  host:     'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  user:     'cadejo_admin',
  password: 'CadejoRDS#2026!',
  database: 'gestion_operaciones_db',
  ssl:      { rejectUnauthorized: false },
})

async function run() {
  await client.connect()
  console.log('Conectado a gestion_operaciones_db')

  // Diagnóstico previo
  const diag = await client.query(`
    WITH movs_sum AS (
      SELECT m.sucursal_id, DATE(m.fecha) AS fecha, m.producto_id,
             ROUND(SUM(CAST(m.detalle->>'total_contado' AS numeric)), 4) AS total_real
      FROM movimientos_inventario m
      WHERE m.tipo = 'conteo_mensual' AND m.aud_usuario != 'sin_contar'
      GROUP BY m.sucursal_id, DATE(m.fecha), m.producto_id
    )
    SELECT ca.id as aud_id, ca.sucursal_id, ca.fecha_conteo::text, ca.estado,
           COUNT(*) as productos_mal
    FROM conteo_auditorias ca
    JOIN conteo_auditoria_items cai ON cai.auditoria_id = ca.id AND cai.auditor_email = ''
    JOIN movs_sum ms ON ms.sucursal_id = ca.sucursal_id AND ms.fecha = ca.fecha_conteo AND ms.producto_id = cai.producto_id
    WHERE ABS(ms.total_real - cai.cantidad_contador) > 0.01
    GROUP BY ca.id, ca.sucursal_id, ca.fecha_conteo, ca.estado
    ORDER BY ca.fecha_conteo DESC, ca.sucursal_id
  `)
  console.log('\nAuditorias con registros incorrectos:')
  console.table(diag.rows)
  const totalMal = diag.rows.reduce((s, r) => s + parseInt(r.productos_mal), 0)
  console.log(`Total productos a corregir: ${totalMal}`)

  // Corrección
  const upd = await client.query(`
    WITH movs_sum AS (
      SELECT m.sucursal_id, DATE(m.fecha) AS fecha, m.producto_id,
             ROUND(SUM(CAST(m.detalle->>'total_contado' AS numeric)), 4) AS total_real
      FROM movimientos_inventario m
      WHERE m.tipo = 'conteo_mensual' AND m.aud_usuario != 'sin_contar'
      GROUP BY m.sucursal_id, DATE(m.fecha), m.producto_id
    ),
    afectados AS (
      SELECT cai.id AS item_id, ms.total_real
      FROM conteo_auditoria_items cai
      JOIN conteo_auditorias ca ON ca.id = cai.auditoria_id
      JOIN movs_sum ms ON ms.sucursal_id = ca.sucursal_id AND ms.fecha = ca.fecha_conteo AND ms.producto_id = cai.producto_id
      WHERE cai.auditor_email = '' AND ABS(ms.total_real - cai.cantidad_contador) > 0.01
    )
    UPDATE conteo_auditoria_items cai_upd
    SET cantidad_contador = a.total_real, updated_at = NOW()
    FROM afectados a
    WHERE cai_upd.id = a.item_id
  `)
  console.log(`\nActualizados: ${upd.rowCount} registros`)

  // Verificar que no quede ninguno mal
  const check = await client.query(`
    WITH movs_sum AS (
      SELECT m.sucursal_id, DATE(m.fecha) AS fecha, m.producto_id,
             ROUND(SUM(CAST(m.detalle->>'total_contado' AS numeric)), 4) AS total_real
      FROM movimientos_inventario m
      WHERE m.tipo = 'conteo_mensual' AND m.aud_usuario != 'sin_contar'
      GROUP BY m.sucursal_id, DATE(m.fecha), m.producto_id
    )
    SELECT COUNT(*) as pendientes
    FROM conteo_auditoria_items cai
    JOIN conteo_auditorias ca ON ca.id = cai.auditoria_id
    JOIN movs_sum ms ON ms.sucursal_id = ca.sucursal_id AND ms.fecha = ca.fecha_conteo AND ms.producto_id = cai.producto_id
    WHERE cai.auditor_email = '' AND ABS(ms.total_real - cai.cantidad_contador) > 0.01
  `)
  console.log(`Registros pendientes despues del fix: ${check.rows[0].pendientes}`)

  await client.end()
}

run().catch(e => { console.error(e); process.exit(1) })
