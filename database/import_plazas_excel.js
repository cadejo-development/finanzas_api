require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

/**
 * import_plazas_excel.js
 *
 * Importa el Catálogo de Plazas desde el Excel oficial al sistema.
 *
 * Estrategia:
 *  1. Lee "Catálogo de Plazas"  → 349 plazas con código, estado, tipo, fechas.
 *  2. Lee "Empleados con Plaza" → 313 enlaces (codigo_empleado → codigo_plaza).
 *  3. Para cada plaza OCUPADA:
 *       - Busca el empleado en DB por código.
 *       - Si el empleado ya tiene plaza_id → actualiza esa plaza con los datos del Excel.
 *       - Si no tiene plaza_id → crea la plaza y enlaza al empleado.
 *  4. Para plazas VACANTES → crea registros nuevos sin enlace a empleado.
 *
 * Uso:
 *   node import_plazas_excel.js            ← dry-run (solo muestra cambios)
 *   node import_plazas_excel.js --apply    ← aplica cambios reales
 */

const { Pool } = require('pg');
const ExcelJS  = require('exceljs');
const path     = require('path');

const APPLY = process.argv.includes('--apply');
const FILE  = path.join('C:\\Users\\administrator\\Downloads', 'Catalogo_de_Plazas_Cadejo 1.xlsx');

const pg = new Pool({
  host:     process.env.DB_HOST,
  port:     5432, database: 'core_db',
  user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl:      { rejectUnauthorized: false },
});

function parseDate(val) {
  if (!val) return null;
  const s = String(val).trim();
  if (!s) return null;
  // Formato DD/MM/YYYY
  const m = s.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
  if (m) return `${m[3]}-${m[2]}-${m[1]}`;
  // Si ya es YYYY-MM-DD
  if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0, 10);
  return null;
}

async function main() {
  console.log(`\nModo: ${APPLY ? '✅ APPLY (cambios reales)' : '🔍 DRY-RUN (solo lectura)'}\n`);

  // ── Leer Excel ────────────────────────────────────────────────────
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.readFile(FILE);

  // Pestaña 1: Catálogo de Plazas
  const wsPlazas = wb.getWorksheet('Catálogo de Plazas');
  const plazasExcel = [];
  wsPlazas.eachRow((row, ri) => {
    if (ri === 1) return;
    const codigo          = String(row.getCell(1).text || '').trim();
    if (!codigo) return;
    plazasExcel.push({
      codigo,
      puesto:           String(row.getCell(2).text || '').trim(),
      sucursal_unidad:  String(row.getCell(3).text || '').trim(),
      fecha_creacion:   parseDate(row.getCell(4).text),
      estado:           String(row.getCell(5).text || '').trim().toLowerCase(),  // ocupada | vacante
      tipo_plaza:       String(row.getCell(6).text || '').trim().toLowerCase(),  // permanente | interina | temporal
      fecha_ocupacion:  parseDate(row.getCell(7).text),
      fecha_liberacion: parseDate(row.getCell(8).text),
    });
  });
  console.log(`Excel: ${plazasExcel.length} plazas leídas`);
  console.log(`  Ocupadas: ${plazasExcel.filter(p => p.estado === 'ocupada').length}`);
  console.log(`  Vacantes: ${plazasExcel.filter(p => p.estado === 'vacante').length}`);

  // Pestaña 2: Empleados con Plaza → mapa codigoEmpleado → codigoPlaza
  const wsEmps = wb.getWorksheet('Empleados con Plaza');
  const empToPlaza = {};  // { '1105': 'AMKJAM001-17', ... }
  wsEmps.eachRow((row, ri) => {
    if (ri === 1) return;
    const codEmp   = String(row.getCell(1).text || '').trim();
    const codPlaza = String(row.getCell(8).text || '').trim();
    if (codEmp && codPlaza) empToPlaza[codEmp] = codPlaza;
  });
  console.log(`Excel: ${Object.keys(empToPlaza).length} enlaces empleado→plaza`);

  // ── Cargar DB ─────────────────────────────────────────────────────
  const dbEmpleados = await pg.query(`
    SELECT id, codigo, cargo_id, departamento_id, plaza_id
    FROM empleados WHERE activo = true
  `);
  const empMap = {};  // { codigoEmpleado: row }
  dbEmpleados.rows.forEach(e => { empMap[String(e.codigo)] = e; });

  const dbPlazas = await pg.query(`SELECT id, codigo FROM plazas`);
  const plazaByCode = {};  // { codigoPlaza: id }
  dbPlazas.rows.forEach(p => { if (p.codigo) plazaByCode[p.codigo] = p.id; });

  const dbCargos = await pg.query(`SELECT id, nombre FROM cargos ORDER BY nombre`);
  const cargoByNombre = {};
  const normalize = s => s.toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')  // quita tildes
    .replace(/\s+/g, ' ').trim();
  dbCargos.rows.forEach(c => { cargoByNombre[normalize(c.nombre)] = c.id; });

  const dbDptos = await pg.query(`SELECT id, nombre FROM departamentos WHERE activo = true`);
  const dptoByNombre = {};
  dbDptos.rows.forEach(d => { dptoByNombre[d.nombre.toLowerCase()] = d.id; });

  const dbSucs = await pg.query(`SELECT id, nombre FROM sucursales WHERE activa = true`);
  const sucByNombre = {};
  dbSucs.rows.forEach(s => { sucByNombre[s.nombre.toLowerCase()] = s.id; });

  // ── Invertir mapa codPlaza → codEmpleado ─────────────────────────
  const plazaToEmp = {};
  for (const [codEmp, codPlaza] of Object.entries(empToPlaza)) {
    plazaToEmp[codPlaza] = codEmp;
  }

  // ── Procesar plazas ───────────────────────────────────────────────
  const stats = { actualizadas: 0, nuevasOcupadas: 0, nuevasVacantes: 0, sinEmpleado: 0, errors: [] };

  for (const plaza of plazasExcel) {
    const commonData = {
      codigo:           plaza.codigo,
      puesto:           plaza.puesto,
      sucursal_unidad:  plaza.sucursal_unidad,
      estado:           plaza.estado,
      tipo_plaza:       plaza.tipo_plaza,
      fecha_creacion:   plaza.fecha_creacion,
      fecha_ocupacion:  plaza.fecha_ocupacion,
      fecha_liberacion: plaza.fecha_liberacion,
    };

    // Resolver cargo_id desde el puesto
    const cargoId = cargoByNombre[normalize(plaza.puesto)] ?? null;
    if (!cargoId) console.log(`  ⚠ Sin cargo en DB para puesto: "${plaza.puesto}" (${plaza.codigo})`);

    if (plaza.estado === 'ocupada') {
      const codEmp = plazaToEmp[plaza.codigo];
      if (!codEmp) {
        stats.errors.push(`Plaza ocupada sin empleado en Excel: ${plaza.codigo}`);
        continue;
      }

      const emp = empMap[codEmp];
      if (!emp) {
        stats.errors.push(`Empleado ${codEmp} no encontrado en DB (plaza ${plaza.codigo})`);
        stats.sinEmpleado++;
        continue;
      }

      if (emp.plaza_id) {
        // Actualizar la plaza existente
        console.log(`  [UPD] Plaza ${plaza.codigo} → empleado ${codEmp} (plaza_id=${emp.plaza_id})`);
        if (APPLY) {
          await pg.query(`
            UPDATE plazas SET
              codigo = $1, puesto = $2, sucursal_unidad = $3,
              estado = $4, tipo_plaza = $5,
              fecha_creacion = $6, fecha_ocupacion = $7, fecha_liberacion = $8,
              updated_at = NOW()
            WHERE id = $9
          `, [
            commonData.codigo, commonData.puesto, commonData.sucursal_unidad,
            commonData.estado, commonData.tipo_plaza,
            commonData.fecha_creacion, commonData.fecha_ocupacion, commonData.fecha_liberacion,
            emp.plaza_id,
          ]);
        }
        stats.actualizadas++;
      } else {
        // El empleado no tiene plaza_id → crear plaza nueva y enlazar
        console.log(`  [NEW-OCP] Plaza ${plaza.codigo} → empleado ${codEmp} (sin plaza_id aún)`);
        if (APPLY) {
          const res = await pg.query(`
            INSERT INTO plazas (cargo_id, departamento_id, codigo, puesto, sucursal_unidad,
              estado, tipo_plaza, fecha_creacion, fecha_ocupacion, fecha_liberacion, activo, created_at, updated_at)
            VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,true,NOW(),NOW())
            RETURNING id
          `, [
            cargoId ?? emp.cargo_id, emp.departamento_id,
            commonData.codigo, commonData.puesto, commonData.sucursal_unidad,
            commonData.estado, commonData.tipo_plaza,
            commonData.fecha_creacion, commonData.fecha_ocupacion, commonData.fecha_liberacion,
          ]);
          await pg.query(`UPDATE empleados SET plaza_id = $1 WHERE id = $2`, [res.rows[0].id, emp.id]);
        }
        stats.nuevasOcupadas++;
      }
    } else {
      // Vacante — crear si no existe ya por código
      if (plazaByCode[plaza.codigo]) {
        console.log(`  [SKIP-VAC] Plaza vacante ${plaza.codigo} ya existe en DB`);
        if (APPLY) {
          await pg.query(`
            UPDATE plazas SET
              puesto = $1, sucursal_unidad = $2, estado = $3, tipo_plaza = $4,
              fecha_creacion = $5, fecha_ocupacion = $6, fecha_liberacion = $7, updated_at = NOW()
            WHERE id = $8
          `, [
            commonData.puesto, commonData.sucursal_unidad, commonData.estado, commonData.tipo_plaza,
            commonData.fecha_creacion, commonData.fecha_ocupacion, commonData.fecha_liberacion,
            plazaByCode[plaza.codigo],
          ]);
        }
      } else {
        console.log(`  [NEW-VAC] Plaza vacante ${plaza.codigo} — ${plaza.puesto} / ${plaza.sucursal_unidad}`);
        if (APPLY) {
          await pg.query(`
            INSERT INTO plazas (cargo_id, departamento_id, codigo, puesto, sucursal_unidad,
              estado, tipo_plaza, fecha_creacion, fecha_ocupacion, fecha_liberacion, activo, created_at, updated_at)
            VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,true,NOW(),NOW())
          `, [
            cargoId ?? null, null,
            commonData.codigo, commonData.puesto, commonData.sucursal_unidad,
            commonData.estado, commonData.tipo_plaza,
            commonData.fecha_creacion, commonData.fecha_ocupacion, commonData.fecha_liberacion,
          ]);
        }
        stats.nuevasVacantes++;
      }
    }
  }

  // ── Resumen ───────────────────────────────────────────────────────
  console.log('\n' + '='.repeat(50));
  console.log('RESUMEN:');
  console.log(`  Plazas actualizadas (existentes):  ${stats.actualizadas}`);
  console.log(`  Plazas nuevas ocupadas creadas:    ${stats.nuevasOcupadas}`);
  console.log(`  Plazas vacantes nuevas creadas:    ${stats.nuevasVacantes}`);
  console.log(`  Empleados no encontrados en DB:    ${stats.sinEmpleado}`);
  if (stats.errors.length) {
    console.log('\nAdvertencias:');
    stats.errors.forEach(e => console.log('  ⚠', e));
  }
  if (!APPLY) console.log('\n→ Ejecuta con --apply para aplicar cambios reales.');
  else console.log('\n✅ Importación completada.');

  await pg.end();
}

main().catch(e => { console.error('Error:', e.message); process.exit(1); });
