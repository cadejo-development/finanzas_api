/**
 * 1. Corregir códigos de empleados (global, tabla empleados):
 *    2535 → 2534  (Immer Flamenco, salario $408.80)
 *    2557 → 2556  (Enrique Garcia, salario $408.80)
 *
 * 2. Excluir lista de empleados de planillas salarios nov Q1 y Q2
 *    usando la tabla planilla_exclusiones (soft-delete persistente)
 */
require('dotenv').config();
const { Client } = require('pg');

// core_db — empleados
const pgCore = new Client({
  host: process.env.DB_HOST, port: process.env.DB_PORT || 5432,
  database: process.env.DB_DATABASE, user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD, ssl: { rejectUnauthorized: false },
});
// rrhh_db — planillas
const pgRrhh = new Client({
  host: process.env.DB_HOST_RRHH, port: process.env.DB_PORT_RRHH || 5432,
  database: process.env.DB_DATABASE_RRHH, user: process.env.DB_USERNAME_RRHH,
  password: process.env.DB_PASSWORD_RRHH, ssl: { rejectUnauthorized: false },
});

// Códigos en la planilla (algunos tienen error, buscamos por nombre también)
const CODIGOS_EXCLUIR = [
  1029, 1271, 1479, 1771, 1805, 1866, 2102, 2191, 2259, 2314,
  2340, 2341, 2342, 2483, 2535, 2557, 2567, 2637, 2641, 2673, 2684,
];

async function main() {
  await pgCore.connect();
  await pgRrhh.connect();

  // ─── 1. Corregir códigos y salarios ─────────────────────────────────────────
  console.log('=== 1. Corrigiendo códigos de empleados ===');

  // Immer: 2535 → 2534
  const immerR = await pgCore.query(
    "SELECT id, nombres, apellidos, codigo, salario_base FROM empleados WHERE codigo = '2535' OR (nombres ILIKE '%immer%' AND apellidos ILIKE '%flamenco%')"
  );
  console.log('Immer candidatos:', immerR.rows.map(r => `id=${r.id} codigo=${r.codigo} ${r.nombres} ${r.apellidos}`).join(', '));

  // Duplicados conocidos: inactivar el malo (codigo erróneo) y corregir salario del bueno
  // Immer: id=338 (2535 malo) → inactivar; id=337 (2534 bueno) → salario 408.80
  if (immerR.rows.length >= 1) {
    const malo  = immerR.rows.find(r => r.codigo === '2535');
    const bueno = immerR.rows.find(r => r.codigo === '2534');
    if (malo) {
      await pgCore.query(
        "UPDATE empleados SET activo = false, salario_manual = true, updated_at = NOW() WHERE id = $1",
        [malo.id]
      );
      console.log(`  ✓ Immer duplicado id=${malo.id} codigo=2535: inactivado`);
    }
    if (bueno) {
      await pgCore.query(
        "UPDATE empleados SET salario_base = 408.80, salario_manual = true, updated_at = NOW() WHERE id = $1",
        [bueno.id]
      );
      console.log(`  ✓ Immer correcto id=${bueno.id} codigo=2534: salario → 408.80`);
    }
  } else {
    console.log('  ⚠ Immer no encontrado');
  }

  // Enrique: id=647 (2557 malo) → inactivar; id=1266 (2556 bueno) → salario 408.80
  const enriqueR = await pgCore.query(
    "SELECT id, nombres, apellidos, codigo, salario_base FROM empleados WHERE codigo IN ('2556','2557') OR (nombres ILIKE '%enrique%' AND apellidos ILIKE '%garcia%')"
  );
  console.log('Enrique candidatos:', enriqueR.rows.map(r => `id=${r.id} codigo=${r.codigo} ${r.nombres} ${r.apellidos}`).join(', '));

  if (enriqueR.rows.length >= 1) {
    const malo  = enriqueR.rows.find(r => r.codigo === '2557');
    const bueno = enriqueR.rows.find(r => r.codigo === '2556');
    if (malo) {
      await pgCore.query(
        "UPDATE empleados SET activo = false, salario_manual = true, updated_at = NOW() WHERE id = $1",
        [malo.id]
      );
      console.log(`  ✓ Enrique duplicado id=${malo.id} codigo=2557: inactivado`);
    }
    if (bueno) {
      await pgCore.query(
        "UPDATE empleados SET salario_base = 408.80, salario_manual = true, updated_at = NOW() WHERE id = $1",
        [bueno.id]
      );
      console.log(`  ✓ Enrique correcto id=${bueno.id} codigo=2556: salario → 408.80`);
    }
  } else {
    console.log('  ⚠ Enrique no encontrado');
  }

  // ─── 2. Buscar planillas nov salarios ───────────────────────────────────────
  console.log('\n=== 2. Planillas de salarios noviembre 2026 ===');
  const planR = await pgRrhh.query(`
    SELECT id, anio, mes, quincena, tipo_planilla, fecha_inicio, fecha_fin, estado
    FROM planillas
    WHERE tipo_planilla = 'salarios'
      AND anio = 2026 AND mes = 11
    ORDER BY quincena
  `);
  planR.rows.forEach(p => console.log(`  id=${p.id} | ${p.anio}-${p.mes} Q${p.quincena} | ${p.fecha_inicio} → ${p.fecha_fin} | ${p.estado}`));

  if (!planR.rows.length) {
    console.log('  ⚠ No se encontraron planillas de salarios de noviembre');
    await pgCore.end(); await pgRrhh.end(); return;
  }

  const planillaIds = planR.rows.map(r => r.id);

  // ─── 3. Buscar empleados por sus códigos ────────────────────────────────────
  console.log('\n=== 3. Buscando empleados por código ===');
  // Para 2535 y 2557 buscamos TAMBIÉN con el código corregido (2534/2556) — hay duplicados
  const codigosExtendidos = [...CODIGOS_EXCLUIR, 2534, 2556];
  const empR = await pgCore.query(
    `SELECT id, nombres, apellidos, codigo FROM empleados
     WHERE codigo = ANY($1::varchar[])
     ORDER BY codigo`,
    [codigosExtendidos.map(String)]
  );

  console.log(`  Encontrados: ${empR.rows.length}`);
  empR.rows.forEach(r => console.log(`  id=${r.id} codigo=${r.codigo} | ${r.nombres} ${r.apellidos}`));

  // Mapa codigo→lista de empleados (puede haber duplicados)
  const empMap = {};
  empR.rows.forEach(r => {
    empMap[r.codigo] = { id: r.id, nombre: `${r.nombres} ${r.apellidos}` };
  });

  // Para cada código original, retornar TODOS los employee ids (incluyendo duplicados)
  const resolverEmpIds = (codigoOriginal) => {
    const ids = [];
    if (codigoOriginal === 2535) {
      if (empMap['2534']) ids.push(empMap['2534']);
      if (empMap['2535']) ids.push(empMap['2535']);
    } else if (codigoOriginal === 2557) {
      if (empMap['2556']) ids.push(empMap['2556']);
      if (empMap['2557']) ids.push(empMap['2557']);
    } else {
      if (empMap[String(codigoOriginal)]) ids.push(empMap[String(codigoOriginal)]);
    }
    return ids;
  };

  // ─── 4. Excluir de planillas ─────────────────────────────────────────────────
  console.log('\n=== 4. Excluyendo de planillas ===');
  let excluidos = 0, noEncontrados = 0;

  for (const planId of planillaIds) {
    console.log(`\n  Planilla id=${planId}:`);
    for (const codigo of CODIGOS_EXCLUIR) {
      const emps = resolverEmpIds(codigo);
      if (!emps.length) {
        console.log(`    ⚠ codigo ${codigo}: no encontrado en empleados`);
        noEncontrados++;
        continue;
      }
      for (const emp of emps) {

        // Verificar si tiene línea en esta planilla
        const lineaR = await pgRrhh.query(
          'SELECT id, estado FROM planilla_lineas WHERE planilla_id = $1 AND empleado_id = $2',
          [planId, emp.id]
        );

        if (!lineaR.rows.length) {
          await pgRrhh.query(
            `INSERT INTO planilla_exclusiones (planilla_id, empleado_id, motivo, created_at, updated_at)
             VALUES ($1, $2, 'Exclusión manual', NOW(), NOW())
             ON CONFLICT (planilla_id, empleado_id) DO NOTHING`,
            [planId, emp.id]
          );
          console.log(`    + codigo ${codigo} id=${emp.id} (${emp.nombre}): sin línea — bloqueado`);
          excluidos++;
          continue;
        }

        const linea = lineaR.rows[0];
        if (linea.estado === 'excluido') {
          await pgRrhh.query(
            `INSERT INTO planilla_exclusiones (planilla_id, empleado_id, motivo, created_at, updated_at)
             VALUES ($1, $2, 'Exclusión manual', NOW(), NOW())
             ON CONFLICT (planilla_id, empleado_id) DO NOTHING`,
            [planId, emp.id]
          );
          console.log(`    = codigo ${codigo} id=${emp.id} (${emp.nombre}): ya excluido`);
          continue;
        }

        await pgRrhh.query(
          'UPDATE planilla_lineas SET estado = $1, updated_at = NOW() WHERE id = $2',
          ['excluido', linea.id]
        );
        await pgRrhh.query(
          `INSERT INTO planilla_exclusiones (planilla_id, empleado_id, motivo, created_at, updated_at)
           VALUES ($1, $2, 'Exclusión manual', NOW(), NOW())
           ON CONFLICT (planilla_id, empleado_id) DO NOTHING`,
          [planId, emp.id]
        );
        console.log(`    ✓ codigo ${codigo} id=${emp.id} (${emp.nombre}): excluido`);
        excluidos++;
      } // end for emps
    }
  }

  console.log(`\n=== Resumen ===`);
  console.log(`  Planillas procesadas: ${planillaIds.length}`);
  console.log(`  Exclusiones aplicadas: ${excluidos}`);
  console.log(`  Códigos no encontrados en DB: ${noEncontrados}`);

  await pgCore.end();
  await pgRrhh.end();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
