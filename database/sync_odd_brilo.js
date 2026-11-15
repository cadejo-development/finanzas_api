/**
 * sync_odd_brilo.js
 * Sincroniza Órdenes de Descuento (ODD) desde Brilo olNomina → planilla_ordenes_descuento en rrhh_db
 *
 * Fuentes Brilo:
 *   - EgresosXEmpleado  → descuentos variables activos por empleado (ODD propiamente dicha)
 *   - PrestamosNmn      → préstamos activos (si tienen egreso asociado)
 *
 * Lógica de upsert: por (empleado_id + brilo_exe_id almacenado en concepto como prefijo "BRILO:NNN")
 * No modifica registros manuales (concepto sin prefijo BRILO:).
 *
 * Uso: node database/sync_odd_brilo.js [--dry-run]
 */

require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const sql  = require('mssql');
const { Client } = require('pg');

const DRY_RUN = process.argv.includes('--dry-run');

// ─── Configuraciones ──────────────────────────────────────────────────────────

const briloCfg = {
  user:     process.env.DB_USERNAME_ORIGEN,
  password: process.env.DB_PASSWORD_ORIGEN,
  server:   process.env.DB_HOST_ORIGEN,
  port:     Number(process.env.DB_PORT_ORIGEN) || 2033,
  database: 'olNomina',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 20000, requestTimeout: 30000 },
};

const rdsCfg = {
  host:     process.env.DB_HOST,
  port:     Number(process.env.DB_PORT) || 5432,
  database: 'rrhh_db',
  user:     process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

function log(msg) { console.log(`[${new Date().toISOString()}] ${msg}`); }

// ─── Main ─────────────────────────────────────────────────────────────────────

async function main() {
  log(`Iniciando sync ODD Brilo → RDS${DRY_RUN ? ' (DRY RUN)' : ''}`);

  // ── 1. Conectar ──────────────────────────────────────────────────────────────
  const brilo = await sql.connect(briloCfg);
  log('Conectado a Brilo olNomina');

  const pg = new Client(rdsCfg);
  await pg.connect();
  log('Conectado a RDS rrhh_db');

  // ── 2. Cargar mapa codigo → id de empleados activos en RDS ──────────────────
  const { rows: empRows } = await pg.query(
    `SELECT id, codigo FROM empleados WHERE activo = true`
  );
  const empMap = {}; // empCodigo (string) → rrhh id
  for (const r of empRows) empMap[String(r.codigo).trim()] = r.id;
  log(`Empleados activos en RDS: ${empRows.length}`);

  // ── 3. Leer EgresosXEmpleado activos desde Brilo ─────────────────────────────
  // Excluye ISSS, AFP (IPR), Renta y registros fijos que son estructurales, no ODD.
  const oddRes = await brilo.request().query(`
    SELECT
      exe.exeId,
      e.empCodigo,
      te.tegNombreAbr   AS concepto,
      te.tegCodigo      AS tipo_codigo,
      exe.exeMontoEgreso,
      exe.exePorcentEgreso,
      CONVERT(date, exe.exeFechaInicio) AS fecha_inicio,
      CONVERT(date, exe.exeFechaFin)   AS fecha_fin,
      exe.exeReferencia,
      exe.exeComentarios
    FROM olNomina.dbo.EgresosXEmpleado exe
    JOIN olComun.dbo.Empleados e ON exe.empId = e.empId
    JOIN olNomina.dbo.TiposEgreso te ON exe.tegId = te.tegId
    LEFT JOIN olNomina.dbo.TiposEgresoXEmpleado txe
      ON txe.empId = exe.empId AND txe.tegId = exe.tegId
    WHERE
      e.empActivo = 1
      AND te.tegISSS  = 0
      AND te.tegIPR   = 0
      AND te.tegRenta = 0
      AND te.tegFijo  = 0
      AND (exe.exeFechaFin IS NULL OR exe.exeFechaFin >= GETDATE())
      AND (txe.tegxeEstado IS NULL OR txe.tegxeEstado = 1)
    ORDER BY e.empCodigo, exe.exeId
  `);

  const odds = oddRes.recordset;
  log(`ODD activas en Brilo: ${odds.length}`);

  // ── 4. Leer préstamos activos con su cuota desde Brilo ───────────────────────
  const prsnRes = await brilo.request().query(`
    SELECT
      prsn.prsnId,
      e.empCodigo,
      tpsn.tpsnNombre                     AS concepto,
      prsn.prsnNumero                     AS referencia,
      prsn.prsnMontoPendienteALiquidar    AS monto_pendiente,
      -- cuota quincenal viene del egreso vinculado al préstamo
      exe.exeMontoEgreso                  AS monto_quincenal,
      CONVERT(date, exe.exeFechaInicio)   AS fecha_inicio,
      CONVERT(date, exe.exeFechaFin)      AS fecha_fin
    FROM olNomina.dbo.PrestamosNmn prsn
    JOIN olComun.dbo.Empleados e          ON prsn.empId   = e.empId
    JOIN olNomina.dbo.TiposPrestamosNmn tpsn ON prsn.tpsnId = tpsn.tpsnId
    LEFT JOIN olNomina.dbo.EgresosXEmpleado exe
      ON  exe.empId = prsn.empId
      AND exe.tegId = prsn.tegId
      AND (exe.exeFechaFin IS NULL OR exe.exeFechaFin >= GETDATE())
    WHERE
      prsn.prsnLiquidado = 0
      AND e.empActivo    = 1
      AND exe.exeMontoEgreso > 0
    ORDER BY e.empCodigo, prsn.prsnId
  `);

  const prestamos = prsnRes.recordset;
  log(`Préstamos activos con cuota en Brilo: ${prestamos.length}`);

  await brilo.close();

  // ── 5. Cargar ODD existentes con prefijo BRILO: en RDS para detectar cambios ─
  const { rows: existingRows } = await pg.query(
    `SELECT id, empleado_id, concepto, monto_quincenal, fecha_inicio, fecha_fin, activo
     FROM planilla_ordenes_descuento
     WHERE concepto LIKE 'BRILO:%'`
  );
  // índice: "BRILO:<tipo_codigo>:<exeId>" → fila existente
  const existingIdx = {};
  for (const r of existingRows) existingIdx[r.concepto] = r;

  // ── 6. Preparar registros a upsert ───────────────────────────────────────────
  let inserted = 0, updated = 0, skipped = 0, noEmp = 0;
  const now = new Date();

  async function upsertOdd({ key, empleadoCodigo, concepto, montoQuincenal, fechaInicio, fechaFin }) {
    const empleadoId = empMap[String(empleadoCodigo).trim()];
    if (!empleadoId) { noEmp++; return; }

    const conceptoKey = `BRILO:${key}`;
    const existing = existingIdx[conceptoKey];

    const montoQ = parseFloat(montoQuincenal) || 0;
    if (montoQ <= 0) { skipped++; return; }

    const fi = fechaInicio ? new Date(fechaInicio).toISOString().slice(0, 10) : null;
    const ff = fechaFin    ? new Date(fechaFin).toISOString().slice(0, 10)    : null;

    if (!existing) {
      if (!DRY_RUN) {
        await pg.query(
          `INSERT INTO planilla_ordenes_descuento
           (empleado_id, concepto, monto_quincenal, fecha_inicio, fecha_fin, activo, created_at, updated_at)
           VALUES ($1, $2, $3, $4, $5, true, $6, $6)`,
          [empleadoId, conceptoKey, montoQ, fi, ff, now]
        );
      }
      log(`  INSERT emp=${empleadoCodigo} → ${conceptoKey} $${montoQ}`);
      inserted++;
    } else {
      const changed =
        parseFloat(existing.monto_quincenal) !== montoQ ||
        (existing.fecha_fin?.toISOString?.().slice(0, 10) ?? null) !== ff ||
        !existing.activo;

      if (changed) {
        if (!DRY_RUN) {
          await pg.query(
            `UPDATE planilla_ordenes_descuento
             SET monto_quincenal=$1, fecha_fin=$2, activo=true, updated_at=$3
             WHERE id=$4`,
            [montoQ, ff, now, existing.id]
          );
        }
        log(`  UPDATE id=${existing.id} emp=${empleadoCodigo} → ${conceptoKey} $${montoQ}`);
        updated++;
      } else {
        skipped++;
      }
      delete existingIdx[conceptoKey]; // marca como procesado
    }
  }

  // Procesar EgresosXEmpleado
  for (const odd of odds) {
    await upsertOdd({
      key: `${odd.tipo_codigo}:${odd.exeId}`,
      empleadoCodigo: odd.empCodigo,
      concepto: odd.concepto,
      montoQuincenal: odd.exeMontoEgreso,
      fechaInicio: odd.fecha_inicio,
      fechaFin: odd.fecha_fin,
    });
  }

  // Procesar Préstamos
  for (const p of prestamos) {
    await upsertOdd({
      key: `PRSN:${p.prsnId}`,
      empleadoCodigo: p.empCodigo,
      concepto: p.concepto + (p.referencia ? ` #${p.referencia}` : ''),
      montoQuincenal: p.monto_quincenal,
      fechaInicio: p.fecha_inicio,
      fechaFin: p.fecha_fin,
    });
  }

  // ── 7. Desactivar ODD que ya no existen en Brilo ─────────────────────────────
  let deactivated = 0;
  for (const [conceptoKey, row] of Object.entries(existingIdx)) {
    if (row.activo) {
      if (!DRY_RUN) {
        await pg.query(
          `UPDATE planilla_ordenes_descuento SET activo=false, updated_at=$1 WHERE id=$2`,
          [now, row.id]
        );
      }
      log(`  DEACTIVATE id=${row.id} → ${conceptoKey}`);
      deactivated++;
    }
  }

  await pg.end();

  // ── 8. Resumen ────────────────────────────────────────────────────────────────
  log('');
  log('═══════════════════════════════════════');
  log(`Resumen${DRY_RUN ? ' (DRY RUN — sin cambios reales)' : ''}:`);
  log(`  Insertados:   ${inserted}`);
  log(`  Actualizados: ${updated}`);
  log(`  Desactivados: ${deactivated}`);
  log(`  Sin cambio:   ${skipped}`);
  log(`  Sin emp RDS:  ${noEmp}`);
  log('═══════════════════════════════════════');
}

main().catch(e => {
  console.error('[ERROR]', e.message);
  process.exit(1);
});
