/**
 * Corrige la planilla de Noviembre 2026 Q1 (planilla_id=10):
 *   - Elimina líneas de empleados con código erróneo: 2535 (id=338) y 2557 (id=647)
 *   - Agrega los correctos: 2534 (id=337), 2556 (id=1266) y el faltante 2504 (id=309)
 *   - Recalcula totales
 *
 * La planilla pasa temporalmente a 'borrador', se corrige, y vuelve a 'aprobada'.
 *
 * DRY_RUN=1 node fix_empleados_planilla_novq1.js  → solo muestra, no modifica
 */
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const { Pool } = require('pg');

const DRY_RUN = process.env.DRY_RUN === '1';

const pgCore = new Pool({
  host: process.env.DB_HOST, port: Number(process.env.DB_PORT) || 5432,
  database: process.env.DB_DATABASE, user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD, ssl: { rejectUnauthorized: false }
});
const pgRrhh = new Pool({
  host: process.env.DB_HOST_RRHH || process.env.DB_HOST,
  port: Number(process.env.DB_PORT_RRHH || process.env.DB_PORT) || 5432,
  database: process.env.DB_DATABASE_RRHH,
  user: process.env.DB_USERNAME_RRHH || process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD_RRHH || process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false }
});

const PLANILLA_ID  = 10;
const ELIMINAR_IDS = [338, 647];   // emp ids con código erróneo que están en planilla
const AGREGAR_IDS  = [337, 1266, 309]; // emp ids correctos que se deben agregar
const FECHA_INICIO = '2026-11-01';
const FECHA_FIN    = '2026-11-15';
const DIAS_QUINCENA = 15;
const ISSS_MAX_EMP  = 15.00;

// ─── Lógica de cálculo (replica PlanillaCalculatorService.php) ──────────────

async function getConfig() {
  const { rows } = await pgRrhh.query(`SELECT clave, valor FROM planilla_config`);
  const cfg = {};
  rows.forEach(r => { cfg[r.clave] = parseFloat(r.valor); });
  return cfg;
}

async function getTablaRenta() {
  const { rows } = await pgRrhh.query(`SELECT * FROM planilla_tabla_renta WHERE activo=true ORDER BY desde`);
  return rows;
}

function calcAFPEmp(salProp, cfg) {
  const tope = cfg['afp_tope_quincenal'] ?? 3814.59;
  const pct  = (cfg['afp_empleado_porcentaje'] ?? 7.25) / 100;
  return Math.round(Math.min(salProp, tope) * pct * 100) / 100;
}
function calcAFPPat(salProp, cfg) {
  const tope = cfg['afp_tope_quincenal'] ?? 3814.59;
  const pct  = (cfg['afp_patronal_porcentaje'] ?? 8.75) / 100;
  return Math.round(Math.min(salProp, tope) * pct * 100) / 100;
}
function calcISSSEmp(salProp, cfg, esPensionado) {
  if (esPensionado) return 0;
  const tope = cfg['isss_tope_quincenal'] ?? 500;
  const pct  = (cfg['isss_empleado_porcentaje'] ?? 3.00) / 100;
  const calc = Math.round(Math.min(salProp, tope) * pct * 100) / 100;
  return Math.round(Math.min(calc, ISSS_MAX_EMP) * 100) / 100;
}
function calcISSSpat(salProp, cfg, esPensionado) {
  if (esPensionado) return 0;
  const tope = cfg['isss_tope_quincenal'] ?? 500;
  const pct  = (cfg['isss_patronal_porcentaje'] ?? 7.50) / 100;
  return Math.round(Math.min(salProp, tope) * pct * 100) / 100;
}
function calcINCAF(salProp, cfg) {
  const pct = (cfg['insaforp_patronal_porcentaje'] ?? 1.00) / 100;
  return Math.round(salProp * pct * 100) / 100;
}
function calcRenta(baseGravable, tabla) {
  if (baseGravable <= 0) return 0;
  for (const t of tabla) {
    const desde = parseFloat(t.desde);
    const hasta = t.hasta != null ? parseFloat(t.hasta) : Infinity;
    if (baseGravable >= desde && baseGravable <= hasta) {
      const cuotaFija = parseFloat(t.cuota_fija);
      const pct       = parseFloat(t.porcentaje) / 100;
      const sobreExc  = parseFloat(t.sobre_exceso_de);
      return Math.round(Math.max(0, cuotaFija + (baseGravable - sobreExc) * pct) * 100) / 100;
    }
  }
  return 0;
}

async function calcularLinea(empId, cfg, tabla, ordenes = []) {
  // Obtener datos del empleado
  const { rows: empRows } = await pgCore.query(
    `SELECT e.id, e.salario_base, e.es_pensionado FROM empleados e WHERE e.id=$1`, [empId]
  );
  if (!empRows.length) throw new Error(`Empleado ${empId} no encontrado`);
  const emp = empRows[0];

  const salBase   = parseFloat(emp.salario_base);
  const salQuinc  = Math.round(salBase / 2 * 100) / 100;
  const diasLab   = DIAS_QUINCENA; // asumimos 15 días (se puede ajustar si hay novedades)
  const salProp   = Math.round(salQuinc * (diasLab / DIAS_QUINCENA) * 100) / 100;
  const esPens    = !!emp.es_pensionado;

  const afpEmp   = calcAFPEmp(salProp, cfg);
  const isssEmp  = calcISSSEmp(salProp, cfg, esPens);
  const baseRenta = Math.round((salProp - afpEmp - isssEmp) * 100) / 100;
  const renta     = calcRenta(baseRenta, tabla);

  let otrosDesc = 0;
  for (const o of ordenes) {
    otrosDesc += Math.round(parseFloat(o.monto_quincenal || 0) * 100) / 100;
  }

  const totalDescEmp = Math.round((afpEmp + isssEmp + renta + otrosDesc) * 100) / 100;
  const salNeto      = Math.round((salProp - totalDescEmp) * 100) / 100;

  const afpPat  = calcAFPPat(salProp, cfg);
  const isssPat = calcISSSpat(salProp, cfg, esPens);
  const incaf   = calcINCAF(salProp, cfg);
  const totPat  = Math.round((afpPat + isssPat + incaf) * 100) / 100;
  const costoTotal = Math.round((salNeto + totPat) * 100) / 100;

  return {
    empleado_id:            empId,
    salario_base:           salQuinc,
    dias_quincena:          DIAS_QUINCENA,
    dias_laborados:         diasLab,
    salario_proporcional:   salProp,
    afp_empleado:           afpEmp,
    isss_empleado:          isssEmp,
    renta:                  renta,
    otros_descuentos:       otrosDesc,
    total_descuentos_empleado: totalDescEmp,
    salario_neto:           salNeto,
    afp_patronal:           afpPat,
    isss_patronal:          isssPat,
    insaforp_patronal:      incaf,
    total_patronal:         totPat,
    costo_total:            costoTotal,
    detalle_descuentos:     JSON.stringify([]),
  };
}

// ─── Órdenes de descuento activas ───────────────────────────────────────────

async function getOrdenes(empId) {
  const { rows } = await pgCore.query(`
    SELECT od.id as orden_id, od.acreedor_id, od.referencia,
           od.monto_q1 as monto_quincenal, a.nombre as acreedor_nombre
    FROM ordenes_descuento od
    JOIN estados_orden_descuento e ON e.id = od.estado_id
    JOIN acreedores a ON a.id = od.acreedor_id
    WHERE od.empleado_id = $1
      AND e.nombre = 'Activa'
      AND od.fecha_inicio <= $2
      AND (od.fecha_fin IS NULL OR od.fecha_fin >= $2)
      AND od.monto_q1 > 0
  `, [empId, FECHA_INICIO]);
  return rows;
}

// ─── Main ────────────────────────────────────────────────────────────────────

async function run() {
  if (DRY_RUN) console.log('\n[DRY RUN — sin cambios reales]\n');

  const cfg   = await getConfig();
  const tabla = await getTablaRenta();

  console.log('\n=== PASO 1: Verificar estado actual ===');
  const { rows: [planilla] } = await pgRrhh.query(`SELECT id, estado, anio, mes, quincena FROM planillas WHERE id=$1`, [PLANILLA_ID]);
  console.log(`Planilla ${PLANILLA_ID}: estado=${planilla.estado}`);
  if (!['borrador', 'aprobada'].includes(planilla.estado)) {
    console.log('ERROR: solo se puede corregir planillas borrador/aprobada');
    process.exit(1);
  }

  console.log('\n=== PASO 2: Empleados a eliminar ===');
  for (const eid of ELIMINAR_IDS) {
    const { rows: lineas } = await pgRrhh.query(
      `SELECT empleado_id, salario_base, salario_neto FROM planilla_lineas WHERE planilla_id=$1 AND empleado_id=$2`,
      [PLANILLA_ID, eid]
    );
    if (lineas.length > 0) {
      console.log(`  Eliminar emp_id=${eid}: sal_base=${lineas[0].salario_base} neto=${lineas[0].salario_neto}`);
    } else {
      console.log(`  emp_id=${eid}: no está en la planilla`);
    }
  }

  console.log('\n=== PASO 3: Empleados a agregar ===');
  const lineasNuevas = [];
  for (const eid of AGREGAR_IDS) {
    const { rows: yaExiste } = await pgRrhh.query(
      `SELECT 1 FROM planilla_lineas WHERE planilla_id=$1 AND empleado_id=$2`, [PLANILLA_ID, eid]
    );
    if (yaExiste.length > 0) {
      console.log(`  emp_id=${eid}: ya está en la planilla — omitir`);
      continue;
    }
    const ordenes = await getOrdenes(eid);
    const linea   = await calcularLinea(eid, cfg, tabla, ordenes);
    lineasNuevas.push(linea);
    console.log(
      `  Agregar emp_id=${eid}: sal_base=${linea.salario_base} ` +
      `afp=${linea.afp_empleado} isss=${linea.isss_empleado} renta=${linea.renta} ` +
      `neto=${linea.salario_neto}`
    );
  }

  if (DRY_RUN) {
    console.log('\n[DRY RUN] Sin cambios. Ejecutar sin DRY_RUN=1 para aplicar.');
    await pgCore.end(); await pgRrhh.end();
    return;
  }

  // Ejecutar cambios en transacción
  const client = await pgRrhh.connect();
  try {
    await client.query('BEGIN');

    // 1. Cambiar a borrador si es aprobada
    if (planilla.estado === 'aprobada') {
      await client.query(`UPDATE planillas SET estado='borrador' WHERE id=$1`, [PLANILLA_ID]);
      console.log('\n  → Planilla cambiada a borrador');
    }

    // 2. Eliminar líneas y movimientos de los empleados incorrectos
    for (const eid of ELIMINAR_IDS) {
      const { rowCount: r1 } = await client.query(
        `DELETE FROM planilla_lineas WHERE planilla_id=$1 AND empleado_id=$2`, [PLANILLA_ID, eid]
      );
      const { rowCount: r2 } = await client.query(
        `DELETE FROM planilla_movimientos WHERE planilla_id=$1 AND empleado_id=$2`, [PLANILLA_ID, eid]
      );
      if (r1 > 0) console.log(`  → Eliminado emp_id=${eid}: ${r1} línea(s), ${r2} movimiento(s)`);
    }

    // 3. Insertar nuevas líneas calculadas
    for (const l of lineasNuevas) {
      await client.query(`
        INSERT INTO planilla_lineas (
          planilla_id, empleado_id,
          salario_base, dias_quincena, dias_laborados,
          salario_proporcional,
          afp_empleado, isss_empleado, renta,
          otros_descuentos, total_descuentos_empleado, salario_neto,
          afp_patronal, isss_patronal, insaforp_patronal, total_patronal, costo_total,
          detalle_descuentos, created_at, updated_at
        ) VALUES (
          $1, $2,
          $3, $4, $5,
          $6,
          $7, $8, $9,
          $10, $11, $12,
          $13, $14, $15, $16, $17,
          $18, NOW(), NOW()
        )
      `, [
        PLANILLA_ID, l.empleado_id,
        l.salario_base, l.dias_quincena, l.dias_laborados,
        l.salario_proporcional,
        l.afp_empleado, l.isss_empleado, l.renta,
        l.otros_descuentos, l.total_descuentos_empleado, l.salario_neto,
        l.afp_patronal, l.isss_patronal, l.insaforp_patronal, l.total_patronal, l.costo_total,
        l.detalle_descuentos,
      ]);
      console.log(`  → Insertado emp_id=${l.empleado_id} neto=${l.salario_neto}`);
    }

    // 4. Recalcular totales de la planilla desde las líneas
    const { rows: [tots] } = await client.query(`
      SELECT
        SUM(salario_proporcional)         AS total_salarios,
        SUM(total_descuentos_empleado)    AS total_descuentos,
        SUM(salario_neto)                 AS total_neto,
        SUM(total_patronal)               AS total_patronal
      FROM planilla_lineas WHERE planilla_id=$1
    `, [PLANILLA_ID]);

    await client.query(`
      UPDATE planillas SET
        total_salarios  = $1,
        total_descuentos = $2,
        total_neto      = $3,
        total_patronal  = $4,
        estado          = 'aprobada',
        updated_at      = NOW()
      WHERE id = $5
    `, [
      tots.total_salarios, tots.total_descuentos,
      tots.total_neto, tots.total_patronal,
      PLANILLA_ID,
    ]);
    console.log('\n  → Totales de planilla actualizados, estado=aprobada');

    await client.query('COMMIT');
    console.log('\n✓ Corrección completada exitosamente.');
  } catch (e) {
    await client.query('ROLLBACK');
    console.error('ERROR — rollback:', e.message);
    throw e;
  } finally {
    client.release();
  }

  await pgCore.end();
  await pgRrhh.end();
}

run().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
