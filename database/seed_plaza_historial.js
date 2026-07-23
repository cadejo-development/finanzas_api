/**
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

 * seed_plaza_historial.js
 *
 * Siembra plaza_historial con los registros actuales:
 *   - Empleados activos con plaza_id  → fecha_fin = null  (siguen en la plaza)
 *   - Empleados inactivos con plaza_id → fecha_fin = fecha_baja o fecha_liberacion
 *
 * Uso:
 *   node seed_plaza_historial.js           ← dry-run
 *   node seed_plaza_historial.js --apply   ← inserta
 */

const { Pool } = require('pg');
const APPLY = process.argv.includes('--apply');

const pg = new Pool({
  host: process.env.DB_HOST,
  port: 5432, database: 'core_db',
  user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
});

async function main() {
  console.log(`Modo: ${APPLY ? '✅ APPLY' : '🔍 DRY-RUN'}\n`);

  // ── Verificar si ya hay datos ──────────────────────────────────────────────
  const { rows: [{ total }] } = await pg.query(`SELECT COUNT(*) AS total FROM plaza_historial`);
  if (parseInt(total) > 0) {
    console.log(`⚠ plaza_historial ya tiene ${total} registros.`);
    if (!APPLY) {
      console.log('  Ejecuta con --apply para re-sembrar (se truncará primero).');
    } else {
      console.log('  Truncando...');
      await pg.query(`TRUNCATE plaza_historial RESTART IDENTITY`);
    }
    if (!APPLY) { await pg.end(); return; }
  }

  // ── 1. Empleados activos con plaza ─────────────────────────────────────────
  const { rows: activos } = await pg.query(`
    SELECT
      e.id          AS empleado_id,
      e.codigo      AS cod_emp,
      e.nombres,
      e.apellidos,
      e.plaza_id,
      p.codigo      AS cod_plaza,
      p.puesto,
      COALESCE(p.fecha_ocupacion, e.fecha_ingreso, CURRENT_DATE) AS fecha_inicio
    FROM empleados e
    JOIN plazas p ON p.id = e.plaza_id
    WHERE e.activo = true AND e.plaza_id IS NOT NULL
    ORDER BY e.apellidos
  `);
  console.log(`Empleados activos con plaza: ${activos.length}`);

  // ── 2. Empleados inactivos con plaza_id (tienen fecha de salida conocida) ──
  const { rows: inactivos } = await pg.query(`
    SELECT
      e.id          AS empleado_id,
      e.codigo      AS cod_emp,
      e.nombres,
      e.apellidos,
      e.plaza_id,
      p.codigo      AS cod_plaza,
      p.puesto,
      COALESCE(p.fecha_ocupacion, e.fecha_ingreso, '2020-01-01'::date) AS fecha_inicio,
      COALESCE(p.fecha_liberacion, CURRENT_DATE) AS fecha_fin
    FROM empleados e
    JOIN plazas p ON p.id = e.plaza_id
    WHERE e.activo = false AND e.plaza_id IS NOT NULL
    ORDER BY e.apellidos
  `);
  console.log(`Empleados inactivos con plaza referenciada: ${inactivos.length}`);

  let insertados = 0;

  // ── Insertar activos ───────────────────────────────────────────────────────
  for (const r of activos) {
    console.log(`  [ACTIVO] ${r.cod_emp} ${r.nombres} ${r.apellidos} → plaza ${r.cod_plaza} desde ${r.fecha_inicio?.toISOString?.()?.slice(0,10) ?? r.fecha_inicio}`);
    if (APPLY) {
      await pg.query(`
        INSERT INTO plaza_historial
          (plaza_id, empleado_id, motivo_entrada, fecha_inicio, fecha_fin, motivo_salida, aud_usuario, created_at, updated_at)
        VALUES ($1, $2, 'ingreso', $3, NULL, NULL, 'seed_inicial', NOW(), NOW())
        ON CONFLICT DO NOTHING
      `, [r.plaza_id, r.empleado_id, r.fecha_inicio]);
      insertados++;
    }
  }

  // ── Insertar inactivos (registros históricos cerrados) ────────────────────
  for (const r of inactivos) {
    console.log(`  [INACTIVO] ${r.cod_emp} ${r.nombres} ${r.apellidos} → plaza ${r.cod_plaza} (cerrado)`);
    if (APPLY) {
      await pg.query(`
        INSERT INTO plaza_historial
          (plaza_id, empleado_id, motivo_entrada, fecha_inicio, fecha_fin, motivo_salida, aud_usuario, created_at, updated_at)
        VALUES ($1, $2, 'ingreso', $3, $4, 'desvinculacion', 'seed_inicial', NOW(), NOW())
        ON CONFLICT DO NOTHING
      `, [r.plaza_id, r.empleado_id, r.fecha_inicio, r.fecha_fin]);
      insertados++;
    }
  }

  console.log(`\nRESUMEN:`);
  console.log(`  Activos:   ${activos.length}`);
  console.log(`  Inactivos: ${inactivos.length}`);
  if (APPLY) console.log(`  Insertados: ${insertados}`);
  else console.log('\n→ Ejecuta con --apply para insertar.');

  await pg.end();
}

main().catch(e => { console.error('Error:', e.message); process.exit(1); });
