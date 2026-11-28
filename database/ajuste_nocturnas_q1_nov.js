/**
 * Ajuste manual de horas nocturnas — Q1 noviembre 2026
 *
 * Actualiza horas_nocturnas_override en planilla_lineas (rrhh_db) para la planilla
 * salarios Q1 noviembre. El override se respeta al Recalcular.
 *
 * Uso:
 *   node database/ajuste_nocturnas_q1_nov.js            → solo lectura (muestra plan)
 *   node database/ajuste_nocturnas_q1_nov.js --apply     → aplica los cambios
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

// Código → horas nocturnas de referencia (valor correcto a aplicar)
const AJUSTES = {
  '1035': 45.00,
  '1297': 34.00,
  '1401': 42.00,
  '1506': 37.00,
  '1567': 12.00,
  '1612': 33.00,
  '1635': 27.00,
  '1639': 45.00,
  '1666': 49.00,
  '1708': 45.00,
  '1728': 44.00,
  '1730': 38.00,
  '1739': 39.00,
  '1776': 14.00,
  '1791': 26.00,
  '1822': 26.00,
  '1824':  0.00,
  '1845': 14.00,
  '1864': 12.00,
  '1898': 52.00,
  '1899': 38.00,
  '1908':  0.00,
  '1909': 15.00,
  '1912': 26.00,
  '1924': 19.50,
  '1925': 12.00,
  '1948':  0.00,
  '1952':  8.00,
  '1953': 28.00,
  '1982':  3.00,
  '2007': 21.00,
  '2018': 29.00,
  '2059': 16.50,
  '2066':  0.00,
  '2086': 24.00,
  '2093': 47.00,
  '2100': 23.00,
  '2101': 31.00,
  '2114': 30.00,
  '2118':  0.00,
  '2120': 53.00,
  '2150': 38.00,
  '2169':  0.00,
  '2170': 62.00,
  '2182':  9.00,
  '2190': 28.00,
  '2194': 16.50,
  '2196':  2.00,
  '2198': 44.00,
  '2210': 32.00,
  '2212': 38.00,
  '2218': 20.00,
  '2223': 32.00,
  '2227': 29.00,
  '2230': 13.00,
  '2231': 27.00,
  '2232': 19.20,
  '2234':  8.00,
  '2249': 36.00,
  '2255': 22.50,
  '2270': 22.00,
  '2275': 48.00,
  '2276':  0.00,
  '2278': 37.00,
  '2286': 44.00,
  '2289': 63.00,
  '2297': 38.00,
  '2300': 21.00,
  '2304': 11.00,
  '2309': 30.00,
  '2316': 31.00,
  '2319': 28.00,
  '2326': 60.00,
  '2328': 46.00,
  '2337':  0.00,
  '2347': 48.00,
  '2352': 31.00,
  '2353': 27.00,
  '2367': 28.00,
  '2368': 47.00,
  '2372': 63.00,
  '2373': 28.00,
  '2392': 42.00,
  '2393': 58.00,
  '2416': 37.00,
  '2419': 49.00,
  '2422': 32.00,
  '2423': 35.00,
  '2435': 35.00,
  '2452': 46.00,
  '2454': 32.00,
  '2467': 46.00,
  '2470': 11.00,
  '2473': 24.50,
  '2478': 48.00,
  '2490': 54.00,
  '2503': 38.00,
  '2520': 28.50,
  '2527': 38.00,
  '2533': 42.00,
  '2538':  0.00,
  '2539': 44.00,
  '2540': 47.00,
  '2542': 13.00,
  '2543': 44.00,
  '2548': 33.00,
  '2551': 33.00,
  '2552': 60.00,
  '2554': 21.00,
  '2556':  0.00,
  '2558': 48.00,
  '2559': 56.00,
  '2560': 29.00,
  '2573': 31.00,
  '2578': 50.00,
  '2580': 38.00,
  '2585': 14.00,
  '2588': 24.50,
  '2589': 17.00,
  '2592': 39.00,
  '2599': 48.00,
  '2600': 26.00,
  '2618': 19.50,
  '2620': 20.00,
  '2625': 44.00,
  '2627': 32.00,
  '2634': 45.00,
  '2640': 46.00,
  '2642': 65.00,
  '2644': 22.00,
  '2646': 58.00,
  '2648': 28.00,
  '2649': 46.00,
  '2652': 23.00,
  '2653': 32.00,
  '2655': 40.00,
  '2656': 39.00,
  '2659': 14.00,
  '2662': 48.00,
  '2666': 65.00,
  '2667': 24.00,
  '2675': 33.00,
  '2677': 22.50,
  '2678': 40.00,
  '2679': 56.00,
  '2681': 49.00,
  '2682': 58.00,
  '2683': 44.00,
  '2687': 20.00,
  '2688': 46.00,
  '2693': 35.00,
  '2694': 22.50,
};

const CODIGOS = Object.keys(AJUSTES);

async function main() {
  const apply = process.argv.includes('--apply');
  await pgCore.connect();
  await pgRrhh.connect();

  // 1. Buscar planilla salarios Q1 noviembre 2026
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
  console.log(`  id=${planilla.id} | ${planilla.tipo_planilla} | ${planilla.fecha_inicio} – ${planilla.fecha_fin} | estado=${planilla.estado}`);

  // 2. Buscar empleados por código
  const empR = await pgCore.query(
    `SELECT id, codigo, nombres || ' ' || apellidos AS nombre FROM empleados
     WHERE codigo = ANY($1::text[]) ORDER BY codigo`,
    [CODIGOS]
  );
  const empMap = {};
  empR.rows.forEach(r => { empMap[r.codigo] = r; });

  const faltantes = CODIGOS.filter(c => !empMap[c]);
  if (faltantes.length) {
    console.warn(`\n⚠️  Códigos no encontrados en empleados: ${faltantes.join(', ')}`);
  }

  // 3. Leer planilla_lineas actuales
  const empIds = Object.values(empMap).map(e => e.id);
  const lineasR = await pgRrhh.query(
    `SELECT empleado_id, horas_nocturnas, horas_nocturnas_override
     FROM planilla_lineas
     WHERE planilla_id = $1 AND empleado_id = ANY($2::int[]) AND estado = 'activo'`,
    [planilla.id, empIds]
  );
  const lineaMap = {};
  lineasR.rows.forEach(r => { lineaMap[r.empleado_id] = r; });

  // 4. Plan de cambios
  console.log(`\n${'Código'.padEnd(7)} ${'Empleado'.padEnd(35)} ${'Calculado'.padStart(10)} ${'Override'.padStart(10)} ${'Ref'.padStart(8)} ${'Acción'}`);
  console.log('─'.repeat(100));

  const ops = [];
  for (const codigo of CODIGOS) {
    const emp = empMap[codigo];
    if (!emp) {
      console.log(`${codigo.padEnd(7)} ${'(no encontrado)'.padEnd(35)}`);
      continue;
    }
    const linea = lineaMap[emp.id];
    if (!linea) {
      console.log(`${codigo.padEnd(7)} ${emp.nombre.padEnd(35)} ${'(sin línea activa)'.padStart(10)}`);
      continue;
    }

    const ref     = AJUSTES[codigo];
    const actual  = Number(linea.horas_nocturnas ?? 0);
    const ovActual = linea.horas_nocturnas_override !== null ? Number(linea.horas_nocturnas_override) : null;

    let accion;
    if (ovActual === ref) {
      accion = 'ya ok (sin cambio)';
    } else {
      accion = `${ovActual ?? 'null'} → ${ref}`;
      ops.push({ empId: emp.id, codigo, nombre: emp.nombre, ref });
    }

    console.log(
      `${codigo.padEnd(7)} ${emp.nombre.padEnd(35)} ` +
      `${String(actual).padStart(10)} ` +
      `${String(ovActual ?? 'null').padStart(10)} ` +
      `${String(ref).padStart(8)} ` +
      accion
    );
  }

  if (!apply) {
    console.log(`\n⚠️  MODO SOLO LECTURA — ${ops.length} operaciones pendientes. Pasa --apply para ejecutar.`);
    console.log('   Tras aplicar, corre Recalcular en la planilla para que los overrides tomen efecto.');
    await pgCore.end(); await pgRrhh.end(); return;
  }

  if (!ops.length) {
    console.log('\n✅ Nada que hacer — todos los overrides ya están en su valor correcto.');
    await pgCore.end(); await pgRrhh.end(); return;
  }

  console.log(`\n=== APLICANDO ${ops.length} OPERACIONES ===`);
  const now = new Date().toISOString();
  let ok = 0, warn = 0;

  for (const op of ops) {
    const r = await pgRrhh.query(
      `UPDATE planilla_lineas
       SET horas_nocturnas_override = $1, updated_at = $2
       WHERE planilla_id = $3 AND empleado_id = $4 AND estado = 'activo'
       RETURNING empleado_id, horas_nocturnas_override`,
      [op.ref, now, planilla.id, op.empId]
    );
    if (r.rowCount) {
      console.log(`  ✓ ${op.codigo} ${op.nombre} → horas_nocturnas_override=${op.ref}`);
      ok++;
    } else {
      console.warn(`  ⚠️  ${op.codigo} ${op.nombre} — no se encontró línea activa (planilla_id=${planilla.id})`);
      warn++;
    }
  }

  console.log(`\n=== RESULTADO: ${ok} actualizados, ${warn} sin línea ===`);
  if (ok > 0) {
    console.log('✅ Ahora ve a la planilla y haz clic en Recalcular para aplicar los overrides.');
  }

  await pgCore.end(); await pgRrhh.end();
}

main().catch(err => { console.error('Error fatal:', err); process.exit(1); });
