/**
 * Fix: Mi script anterior inactivó los registros ACTIVOS de Immer (id=338) y Enrique (id=647).
 * Esos eran los empleados reales de trabajo — los reactivo y corrijo los códigos.
 * También limpio la tabla planilla_exclusiones de los IDs afectados.
 */
require('dotenv').config();
const { Client } = require('pg');

const pgCore = new Client({
  host: process.env.DB_HOST, port: process.env.DB_PORT || 5432,
  database: process.env.DB_DATABASE, user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD, ssl: { rejectUnauthorized: false },
});
const pgRrhh = new Client({
  host: process.env.DB_HOST_RRHH, port: process.env.DB_PORT_RRHH || 5432,
  database: process.env.DB_DATABASE_RRHH, user: process.env.DB_USERNAME_RRHH,
  password: process.env.DB_PASSWORD_RRHH, ssl: { rejectUnauthorized: false },
});

async function main() {
  await pgCore.connect();
  await pgRrhh.connect();

  console.log('=== Estado actual ===');
  const antes = await pgCore.query(
    "SELECT id, codigo, nombres, apellidos, salario_base, activo FROM empleados WHERE id IN (337, 338, 647, 1266)"
  );
  antes.rows.forEach(r => console.log(`  id=${r.id} codigo=${r.codigo} activo=${r.activo} sal=${r.salario_base} | ${r.nombres} ${r.apellidos}`));

  // ─── Immer: id=338 era el activo (código 2535 → corregir a 2534) ───────────
  // Primero cambiar el código del registro inactivo (337) para liberar '2534'
  await pgCore.query(
    "UPDATE empleados SET codigo = '2535_OLD', updated_at = NOW() WHERE id = 337"
  );
  console.log('  id=337: codigo → 2535_OLD (libera 2534)');

  // Reactivar id=338 y corregir código a 2534
  await pgCore.query(
    "UPDATE empleados SET activo = true, codigo = '2534', salario_base = 408.80, salario_manual = true, updated_at = NOW() WHERE id = 338"
  );
  console.log('  id=338: reactivado, codigo → 2534, salario → 408.80');

  // ─── Enrique: id=647 era el activo (código 2557 → corregir a 2556) ──────────
  // Cambiar código del registro inactivo (1266) para liberar '2556'
  await pgCore.query(
    "UPDATE empleados SET codigo = '2557_OLD', updated_at = NOW() WHERE id = 1266"
  );
  console.log('  id=1266: codigo → 2557_OLD (libera 2556)');

  // Reactivar id=647 y corregir código a 2556
  await pgCore.query(
    "UPDATE empleados SET activo = true, codigo = '2556', salario_base = 408.80, salario_manual = true, updated_at = NOW() WHERE id = 647"
  );
  console.log('  id=647: reactivado, codigo → 2556, salario → 408.80');

  // ─── Limpiar planilla_exclusiones de TODOS los IDs afectados ─────────────────
  const delExcl = await pgRrhh.query(
    "DELETE FROM planilla_exclusiones WHERE planilla_id IN (10, 11) AND empleado_id IN (337, 338, 647, 1266) RETURNING planilla_id, empleado_id"
  );
  console.log(`\n  Exclusiones eliminadas: ${delExcl.rows.length}`);
  delExcl.rows.forEach(r => console.log(`    planilla=${r.planilla_id} emp_id=${r.empleado_id}`));

  // ─── Verificar estado final ───────────────────────────────────────────────────
  console.log('\n=== Estado final ===');
  const despues = await pgCore.query(
    "SELECT id, codigo, nombres, apellidos, salario_base, activo FROM empleados WHERE id IN (337, 338, 647, 1266)"
  );
  despues.rows.forEach(r => console.log(`  id=${r.id} codigo=${r.codigo} activo=${r.activo} sal=${r.salario_base} | ${r.nombres} ${r.apellidos}`));

  // ─── Verificar líneas en planillas para id=338 y id=647 ─────────────────────
  const lineas = await pgRrhh.query(
    "SELECT planilla_id, empleado_id, estado, salario_proporcional FROM planilla_lineas WHERE planilla_id IN (10, 11) AND empleado_id IN (338, 647) ORDER BY planilla_id"
  );
  console.log('\n=== Líneas de planilla (338, 647) ===');
  if (!lineas.rows.length) {
    console.log('  Sin líneas — necesitan agregarse manualmente a Q1 (planilla 10)');
    console.log('  Q2 (planilla 11) debería recalcularse para incluirlos');
  } else {
    lineas.rows.forEach(r => console.log(`  planilla=${r.planilla_id} emp_id=${r.empleado_id} estado=${r.estado} sal=${r.salario_proporcional}`));
  }

  await pgCore.end();
  await pgRrhh.end();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
