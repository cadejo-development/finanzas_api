/**
 * Ajuste manual de horas extras — Q1 noviembre 2026
 *
 * Actualiza horas_extras_override en planilla_lineas (rrhh_db) para la planilla
 * salarios Q1 noviembre (planilla_id=10). El override persiste en Recalcular;
 * tras correr este script, el usuario debe hacer Recalcular para aplicar los valores.
 *
 * Uso:
 *   node database/ajuste_horas_extras_q1_nov.js            → solo lectura (muestra plan)
 *   node database/ajuste_horas_extras_q1_nov.js --apply     → aplica los cambios
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

// Código → horas extras ref (según hojas de referencia, 3 imágenes)
const AJUSTES = {
  '1248': 6,
  '1297': 0,
  '1372': 4,
  '1486': 4,
  '1502': 4,
  '1506': 0,
  '1612': 0,
  '1639': 7,
  '1708': 7,
  '1730': 0,
  '1773': 0,
  '1791': 0,
  '1817': 4,
  '1824': 0,
  '1892': 0,
  '1925': 18,
  '1952': 4,
  '1953': 21,
  '1982': 0,
  '2066': 0,
  '2074': 2,
  '2086': 2,
  '2118': 0,
  '2150': 7,
  '2169': 0,
  '2182': 13,
  '2190': 16,
  '2208': 0,
  '2231': 0,
  '2234': 14,
  '2250': 4,
  '2277': 48,
  '2304': 0,
  '2319': 5,
  '2326': 0,
  '2347': 13,
  '2386': 8,
  '2416': 3,
  '2419': 10,
  '2454': 0,
  '2467': 12,
  '2478': 0,
  '2490': 2.5,
  '2502': 0,
  '2527': 13,
  '2538': 0,
  '2543': 0,
  '2552': 4,
  '2555': 4,
  '2573': 0,
  '2585': 12,
  '2589': 0,
  '2599': 14,
  '2604': 4,
  '2614': 15,
  '2620': 11,
  '2634': 9,
  '2640': 6,
  '2649': 0,
  '2660': 0,
  '2666': 0,
  '2667': 20,
  '2688': 15,
};

const CODIGOS = Object.keys(AJUSTES);

async function main() {
  const apply = process.argv.includes('--apply');
  await pgCore.connect();
  await pgRrhh.connect();

  // 1. Buscar la planilla Q1 noviembre 2026 de salarios
  const planR = await pgRrhh.query(`
    SELECT id, tipo_planilla, fecha_inicio::text, fecha_fin::text, estado
    FROM planillas
    WHERE tipo_planilla = 'salarios'
      AND fecha_inicio = '2026-11-01'
      AND fecha_fin    = '2026-11-15'
    ORDER BY id DESC
    LIMIT 1
  `);

  if (!planR.rows.length) {
    console.error('❌ No se encontró planilla salarios Q1 noviembre 2026.');
    await pgCore.end(); await pgRrhh.end(); return;
  }
  const planilla = planR.rows[0];
  console.log(`\n=== PLANILLA ===`);
  console.log(`  id=${planilla.id} | tipo=${planilla.tipo_planilla} | ${planilla.fecha_inicio} – ${planilla.fecha_fin} | estado=${planilla.estado}`);

  // 2. Buscar empleados por código
  const empR = await pgCore.query(
    `SELECT id, codigo, nombres || ' ' || apellidos AS nombre FROM empleados
     WHERE codigo = ANY($1::text[]) ORDER BY codigo`,
    [CODIGOS]
  );
  const empMap = {}; // codigo → {id, nombre}
  empR.rows.forEach(r => { empMap[r.codigo] = r; });

  const faltantes = CODIGOS.filter(c => !empMap[c]);
  if (faltantes.length) {
    console.warn(`\n⚠️  Empleados no encontrados por código: ${faltantes.join(', ')}`);
  }

  // 3. Leer planilla_lineas actuales
  const empIds = Object.values(empMap).map(e => e.id);
  const lineasR = await pgRrhh.query(
    `SELECT empleado_id, horas_extras, horas_extras_override, salario_base
     FROM planilla_lineas
     WHERE planilla_id = $1 AND empleado_id = ANY($2::int[]) AND estado = 'activo'`,
    [planilla.id, empIds]
  );
  const lineaMap = {}; // empleado_id → {horas_extras, horas_extras_override, salario_base}
  lineasR.rows.forEach(r => { lineaMap[r.empleado_id] = r; });

  // 4. Mostrar plan
  console.log(`\n${'Código'.padEnd(8)} ${'Empleado'.padEnd(32)} ${'V8 (actual)'.padStart(12)} ${'Override'.padStart(10)} ${'Ref'.padStart(6)} ${'Acción'.padEnd(20)}`);
  console.log('─'.repeat(95));

  const ops = [];
  for (const codigo of CODIGOS) {
    const emp = empMap[codigo];
    if (!emp) {
      console.log(`${codigo.padEnd(8)} ${'(no encontrado)'.padEnd(32)}`);
      continue;
    }
    const linea = lineaMap[emp.id];
    if (!linea) {
      console.log(`${codigo.padEnd(8)} ${emp.nombre.padEnd(32)} ${'(sin línea)'.padStart(12)}`);
      continue;
    }

    const ref            = AJUSTES[codigo];
    const actual         = Number(linea.horas_extras ?? 0);
    const overrideActual = linea.horas_extras_override !== null ? Number(linea.horas_extras_override) : null;

    let accion;
    if (overrideActual === ref) {
      accion = 'ya ok (sin cambio)';
    } else {
      accion = `override: ${overrideActual ?? 'null'} → ${ref}`;
      ops.push({ empId: emp.id, ref, salBase: Number(linea.salario_base) });
    }

    console.log(
      `${codigo.padEnd(8)} ${emp.nombre.padEnd(32)} ` +
      `${String(actual).padStart(12)} ` +
      `${String(overrideActual ?? 'null').padStart(10)} ` +
      `${String(ref).padStart(6)} ` +
      accion
    );
  }

  if (!apply) {
    console.log(`\n⚠️  MODO SOLO LECTURA — ${ops.length} operaciones pendientes. Pasa --apply para ejecutar.`);
    console.log('   Tras aplicar, corre Recalcular en la planilla para aplicar los overrides.');
    await pgCore.end(); await pgRrhh.end(); return;
  }

  if (!ops.length) {
    console.log('\n✅ Nada que hacer — todos los overrides ya están aplicados.');
    await pgCore.end(); await pgRrhh.end(); return;
  }

  console.log(`\n=== APLICANDO ${ops.length} OPERACIONES ===`);
  const now = new Date().toISOString();

  for (const op of ops) {
    const r = await pgRrhh.query(
      `UPDATE planilla_lineas
       SET horas_extras_override = $1, updated_at = $2
       WHERE planilla_id = $3 AND empleado_id = $4 AND estado = 'activo'
       RETURNING empleado_id, horas_extras_override`,
      [op.ref, now, planilla.id, op.empId]
    );
    if (r.rowCount) {
      console.log(`  ✓ emp_id=${op.empId} → horas_extras_override=${op.ref}`);
    } else {
      console.warn(`  ⚠️  emp_id=${op.empId} — no se encontró línea activa`);
    }
  }

  // 5. Verificación final
  const verR = await pgRrhh.query(
    `SELECT pl.empleado_id, pl.horas_extras, pl.horas_extras_override
     FROM planilla_lineas pl
     WHERE pl.planilla_id = $1 AND pl.empleado_id = ANY($2::int[]) AND pl.estado = 'activo'
     ORDER BY pl.empleado_id`,
    [planilla.id, empIds]
  );
  console.log(`\n=== OVERRIDES ACTIVOS TRAS OPERACIÓN ===`);
  verR.rows.forEach(r => {
    const emp = Object.values(empMap).find(e => e.id === r.empleado_id);
    console.log(`  emp_id=${r.empleado_id} ${(emp?.nombre ?? '').padEnd(30)} horas_v8=${r.horas_extras} override=${r.horas_extras_override}`);
  });

  console.log('\n✅ Listo. Ahora corre Recalcular en la planilla para aplicar los overrides.');
  await pgCore.end(); await pgRrhh.end();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
