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

const CODIGOS = [1612, 2504, 2534, 2556, 2578, 2597, 2604, 2659, 2665, 2668, 2679, 2685, 2698, 2699];

async function main() {
  await pgCore.connect();
  await pgRrhh.connect();

  // 1. Buscar empleados
  const empR = await pgCore.query(
    `SELECT id, nombres, apellidos, codigo, salario_base, activo, fecha_ingreso
     FROM empleados WHERE codigo = ANY($1::varchar[]) ORDER BY codigo`,
    [CODIGOS.map(String)]
  );
  console.log('=== Empleados ===');
  empR.rows.forEach(r => console.log(`  id=${r.id} codigo=${r.codigo} | ${r.nombres} ${r.apellidos} | salario=${r.salario_base} | activo=${r.activo} | ingreso=${r.fecha_ingreso}`));

  const empIds = empR.rows.map(r => r.id);

  // 2. Verificar líneas en planilla Q1 (id=10) y Q2 (id=11)
  const lineasR = await pgRrhh.query(
    `SELECT planilla_id, empleado_id, estado, salario_proporcional
     FROM planilla_lineas WHERE planilla_id IN (10, 11) AND empleado_id = ANY($1::int[])
     ORDER BY empleado_id, planilla_id`,
    [empIds]
  );
  console.log('\n=== Líneas en planillas ===');
  lineasR.rows.forEach(r => console.log(`  planilla=${r.planilla_id} emp_id=${r.empleado_id} estado=${r.estado} sal=${r.salario_proporcional}`));

  // 3. Verificar exclusiones
  const exclR = await pgRrhh.query(
    `SELECT planilla_id, empleado_id, motivo FROM planilla_exclusiones
     WHERE planilla_id IN (10, 11) AND empleado_id = ANY($1::int[])
     ORDER BY empleado_id, planilla_id`,
    [empIds]
  );
  console.log('\n=== Exclusiones (planilla_exclusiones) ===');
  if (!exclR.rows.length) console.log('  Ninguna');
  exclR.rows.forEach(r => console.log(`  planilla=${r.planilla_id} emp_id=${r.empleado_id} | ${r.motivo}`));

  // 4. Verificar tipo_planilla asignado
  const tipoR = await pgCore.query(
    `SELECT etp.empleado_id, tp.slug, etp.fecha_inicio, etp.fecha_fin
     FROM empleado_tipo_planilla etp
     JOIN tipo_planilla tp ON tp.id = etp.tipo_planilla_id
     WHERE etp.empleado_id = ANY($1::int[])
     ORDER BY etp.empleado_id`,
    [empIds]
  );
  console.log('\n=== Tipo planilla asignado ===');
  tipoR.rows.forEach(r => console.log(`  emp_id=${r.empleado_id} tipo=${r.slug} | desde=${r.fecha_inicio} hasta=${r.fecha_fin}`));

  await pgCore.end();
  await pgRrhh.end();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
