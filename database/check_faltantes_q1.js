/**
 * Empleados que están en Q2 (planilla 11) pero NO en Q1 (planilla 10)
 * + empleados que deberían estar en Q1 pero no aparecen (Jhony 2504 y otros)
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

  // IDs de los 14 faltantes según Marcela
  const IDS_MARCELA = [35, 309, 338, 647, 1286, 1306, 1313, 1368, 1374, 1377, 1388, 1394, 1407, 1408];

  // 1. Desvinculaciones en noviembre para estos empleados
  console.log('=== Desvinculaciones nov 2026 ===');
  const desvR = await pgRrhh.query(
    `SELECT empleado_id, fecha_efectiva, estado, tipo, observaciones
     FROM desvinculaciones
     WHERE empleado_id = ANY($1::int[])
       AND fecha_efectiva >= '2026-11-01' AND fecha_efectiva <= '2026-11-30'
     ORDER BY fecha_efectiva`,
    [IDS_MARCELA]
  );
  if (!desvR.rows.length) console.log('  Ninguna desvinculación en noviembre');
  desvR.rows.forEach(r => console.log(`  emp_id=${r.empleado_id} | fecha=${r.fecha_efectiva} | ${r.estado} | ${r.tipo} | ${r.observaciones}`));

  // 2. Líneas en Q1 y Q2 para todos los IDs
  console.log('\n=== Líneas en planillas ===');
  const linR = await pgRrhh.query(
    `SELECT planilla_id, empleado_id, estado, salario_proporcional
     FROM planilla_lineas WHERE planilla_id IN (10, 11) AND empleado_id = ANY($1::int[])
     ORDER BY empleado_id, planilla_id`,
    [IDS_MARCELA]
  );
  const lineasMap = {};
  linR.rows.forEach(r => {
    if (!lineasMap[r.empleado_id]) lineasMap[r.empleado_id] = {};
    lineasMap[r.empleado_id][r.planilla_id] = r;
  });

  IDS_MARCELA.forEach(id => {
    const q1 = lineasMap[id]?.[10];
    const q2 = lineasMap[id]?.[11];
    const q1str = q1 ? `Q1=${q1.estado}($${q1.salario_proporcional})` : 'Q1=FALTA';
    const q2str = q2 ? `Q2=${q2.estado}($${q2.salario_proporcional})` : 'Q2=FALTA';
    console.log(`  emp_id=${id} | ${q1str} | ${q2str}`);
  });

  // 3. Estado activo + fecha_ingreso de los faltantes de Q1
  console.log('\n=== Empleados sin línea en Q1 ===');
  const sinQ1 = IDS_MARCELA.filter(id => !lineasMap[id]?.[10] || lineasMap[id][10].estado === 'excluido');
  const empR = await pgCore.query(
    `SELECT id, codigo, nombres, apellidos, salario_base, activo, fecha_ingreso
     FROM empleados WHERE id = ANY($1::int[]) ORDER BY codigo`,
    [sinQ1]
  );
  empR.rows.forEach(r => console.log(`  id=${r.id} codigo=${r.codigo} activo=${r.activo} sal=${r.salario_base} ingreso=${r.fecha_ingreso?.toISOString?.()?.slice(0,10)} | ${r.nombres} ${r.apellidos}`));

  // 4. Desvinculaciones en general para los activo=false
  console.log('\n=== Desvinculaciones generales (últimas) para activo=false ===');
  const inactivosIds = empR.rows.filter(r => !r.activo).map(r => r.id);
  if (inactivosIds.length) {
    const desvGen = await pgRrhh.query(
      `SELECT DISTINCT ON (empleado_id) empleado_id, fecha_efectiva, estado, tipo
       FROM desvinculaciones WHERE empleado_id = ANY($1::int[])
       ORDER BY empleado_id, fecha_efectiva DESC`,
      [inactivosIds]
    );
    desvGen.rows.forEach(r => console.log(`  emp_id=${r.empleado_id} | última desvinculacion=${r.fecha_efectiva} | estado=${r.estado} | tipo=${r.tipo}`));
    if (!desvGen.rows.length) console.log('  Sin desvinculaciones registradas');
  }

  await pgCore.end();
  await pgRrhh.end();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
