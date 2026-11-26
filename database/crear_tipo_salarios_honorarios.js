/**
 * Crea registro de tipo_planilla='salarios' para empleados que:
 *   1. Solo tienen tipo_planilla='honorarios' (sin registro de salarios)
 *   2. No tienen desvinculación aprobada
 *
 * Regla fecha_inicio salarios:
 *   - Si honorarios.fecha_fin existe y es válida: salarios inicia fecha_fin + 1 día
 *   - Si fecha_fin es null o < fecha_inicio (dato raro): salarios inicia desde fecha_ingreso del empleado
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

function addDays(dateStr, days) {
  const d = new Date(dateStr);
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

async function main() {
  await pgCore.connect();
  await pgRrhh.connect();

  // 1. Obtener tipo_planilla ids
  const tpR = await pgCore.query(
    "SELECT id, slug FROM tipo_planilla WHERE slug IN ('honorarios', 'salarios')"
  );
  const tpMap = {};
  tpR.rows.forEach(r => { tpMap[r.slug] = r.id; });
  console.log(`tipo_planilla ids: honorarios=${tpMap.honorarios} salarios=${tpMap.salarios}`);

  // 2. Empleados activos con honorarios pero sin salarios
  const sinSalariosR = await pgCore.query(`
    SELECT
      e.id, e.nombres, e.apellidos, e.codigo, e.fecha_ingreso, e.activo,
      etp.fecha_inicio AS hon_inicio, etp.fecha_fin AS hon_fin
    FROM empleados e
    JOIN empleado_tipo_planilla etp ON etp.empleado_id = e.id
    WHERE etp.tipo_planilla_id = $1
      AND NOT EXISTS (
        SELECT 1 FROM empleado_tipo_planilla etp2
        WHERE etp2.empleado_id = e.id AND etp2.tipo_planilla_id = $2
      )
    ORDER BY e.codigo
  `, [tpMap.honorarios, tpMap.salarios]);

  console.log(`\nEmpleados con honorarios sin salarios: ${sinSalariosR.rows.length}`);

  // 3. Desvinculaciones aprobadas para filtrar los que ya se fueron
  const empIds = sinSalariosR.rows.map(r => r.id);
  const desvR = await pgRrhh.query(
    "SELECT DISTINCT empleado_id FROM desvinculaciones WHERE estado = 'aprobado' AND empleado_id = ANY($1::int[])",
    [empIds]
  );
  const desvinculadosSet = new Set(desvR.rows.map(r => r.empleado_id));
  console.log(`Desvinculados (filtrar): ${desvinculadosSet.size}`);

  // 4. Filtrar y calcular fecha_inicio salarios
  const aCrear = [];
  for (const r of sinSalariosR.rows) {
    if (desvinculadosSet.has(r.id)) {
      console.log(`  SKIP desvinculado: ${r.codigo} ${r.nombres} ${r.apellidos}`);
      continue;
    }

    // Calcular fecha_inicio del tipo salarios
    let salInicio;
    const honFin = r.hon_fin ? new Date(r.hon_fin) : null;
    const honInicio = r.hon_inicio ? new Date(r.hon_inicio) : null;

    if (honFin && honInicio && honFin >= honInicio) {
      // fecha_fin válida: salarios inicia el día siguiente
      salInicio = addDays(r.hon_fin, 1);
    } else {
      // Sin fecha_fin o dato raro: salarios inicia desde fecha_ingreso
      salInicio = r.fecha_ingreso ? new Date(r.fecha_ingreso).toISOString().slice(0, 10) : '2026-01-01';
    }

    aCrear.push({ id: r.id, codigo: r.codigo, nombre: `${r.nombres} ${r.apellidos}`, honFin: r.hon_fin, salInicio });
  }

  console.log(`\nA crear tipo_planilla salarios: ${aCrear.length}`);
  aCrear.forEach(x => console.log(`  ${x.codigo} ${x.nombre} | hon_fin=${x.honFin} → sal_inicio=${x.salInicio}`));

  if (!aCrear.length) {
    console.log('Nada que crear.');
    await pgCore.end(); await pgRrhh.end(); return;
  }

  // 5. Insertar
  let creados = 0;
  const now = new Date().toISOString();
  for (const x of aCrear) {
    await pgCore.query(
      `INSERT INTO empleado_tipo_planilla (empleado_id, tipo_planilla_id, fecha_inicio, created_at, updated_at)
       VALUES ($1, $2, $3, $4, $4)
       ON CONFLICT DO NOTHING`,
      [x.id, tpMap.salarios, x.salInicio, now]
    );
    creados++;
    console.log(`  ✓ ${x.codigo} ${x.nombre}: salarios desde ${x.salInicio}`);
  }

  console.log(`\n=== Resumen ===`);
  console.log(`  Creados: ${creados}`);
  console.log(`  Saltados (desvinculados): ${desvinculadosSet.size}`);

  await pgCore.end();
  await pgRrhh.end();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
