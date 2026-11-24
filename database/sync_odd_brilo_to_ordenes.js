/**
 * sync_odd_brilo_to_ordenes.js
 * Sincroniza Préstamos/Descuentos Programados de Brilo → core_db.ordenes_descuento
 *
 * Fuente: olNomina.PrestamosNmn (préstamos activos, generados, no liquidados)
 *         olNomina.TiposPlaXPrestamoNmn (montos Q1/Q2 y fechas)
 *
 * Destino: core_db.ordenes_descuento
 *
 * Lógica:
 *  - Dedup por notas LIKE '[BRILO:NNN]' (prsnId almacenado en notas)
 *  - Auto-crea acreedores faltantes en core_db.acreedores
 *  - Solo inserta/actualiza; nunca elimina (HR puede gestionar manualmente)
 *  - Préstamos que ya no estén activos en Brilo: se dejan intactos (no auto-cancela)
 *
 * Uso: node database/sync_odd_brilo_to_ordenes.js [--dry-run]
 */

require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const sql     = require('mssql');
const { Pool } = require('pg');

const DRY_RUN = process.argv.includes('--dry-run');

// ─── Conexiones ───────────────────────────────────────────────────────────────

const briloCfg = {
  user:     process.env.DB_USERNAME_ORIGEN,
  password: process.env.DB_PASSWORD_ORIGEN,
  server:   process.env.DB_HOST_ORIGEN,
  port:     Number(process.env.DB_PORT_ORIGEN) || 2033,
  database: 'olNomina',
  options:  { trustServerCertificate: true, encrypt: false, connectTimeout: 20000, requestTimeout: 60000 },
};

const pgCfg = {
  host:     process.env.DB_HOST,
  port:     Number(process.env.DB_PORT) || 5432,
  database: 'core_db',
  user:     process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  ssl:      { rejectUnauthorized: false },
};

// ─── Normalización para comparar nombres sin importar acentos/case ───────────

function normalize(s) {
  return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

// ─── Mapa estático: nombre Brilo (exacto, case-sensitive) → datos acreedor ───
// Claves = tpsnNombre real de Brilo. Si no hay match por clave exacta,
// se intenta match normalizado contra los acreedores existentes en core_db.
// Si tampoco, se auto-crea con tipo_acreedor_id inferido del nombre.

const ACREEDOR_MAP = {
  // Nombres exactos confirmados de Brilo (tpsnNombre)
  'AMERICA CENTRAL':   { nombre: 'Banco América Central',                  tipo: 1 },
  'BANCO DAVIVIENDA':  { nombre: 'Banco Davivienda',                       tipo: 1 },
  'SERVIFINANZAS':     { nombre: 'Servifinanzas',                          tipo: 5 },
  'Banco Agricola':    { nombre: 'Banco Agrícola',                         tipo: 1 },
  'FSV':               { nombre: 'FSV',                                    tipo: 2 },
  'AHORRO MMTO':       { nombre: 'Ahorro Mantenimiento',                   tipo: 5 },
  'BANCO HIPOTECARIO': { nombre: 'Banco Hipotecario',                      tipo: 1 },
  'BANCO AZUL':        { nombre: 'Banco Azul',                             tipo: 1 },
  'BANCO CUSCATLAN':   { nombre: 'Banco Cuscatlán',                        tipo: 1 },
  'PRESTAFACIL ACCIONES INTEGRADAS, S.A. DE C.V.': { nombre: 'Prestafacil Acciones Integradas',  tipo: 5 },
  'HABITAT':           { nombre: 'HABITAT',                                tipo: 5 },
  'EMBARGOS':          { nombre: 'Embargos Judiciales',                    tipo: 3 },
  'FIADO APP, S.A. DE C.V.': { nombre: 'Fiado App',                       tipo: 5 },
  'COMEDICA DE R.L.':  { nombre: 'Comedica de R.L.',                       tipo: 4 },
  // Otros posibles (no vistos aún pero pueden aparecer)
  'CADEJO S/INT':      { nombre: 'Cadejo Interno',                         tipo: 5 },
  'CADEJO C/INT':      { nombre: 'Cadejo Interno',                         tipo: 5 },
  'PROMERICA':         { nombre: 'Banco Promerica',                        tipo: 1 },
  'B TRABAJADORES':    { nombre: 'Banco de Trabajadores',                  tipo: 1 },
  'F.S.V':             { nombre: 'FSV',                                    tipo: 2 },
  'VIALIDAD':          { nombre: 'Vialidad',                               tipo: 2 },
  'EMBARGOS JUDICIALES': { nombre: 'Embargos Judiciales',                  tipo: 3 },
  'PGR':               { nombre: 'PGR',                                    tipo: 2 },
};

// ─── Helper: inferir tipo_acreedor_id desde el nombre si no está en el mapa ──

function inferirTipo(nombre) {
  const n = nombre.toUpperCase();
  if (n.includes('BANCO') || n.includes('PROMERICA') || n.includes('DAVIVIENDA') || n.includes('AGRICOLA') || n.includes('CUSCATLAN')) return 1;
  if (n.includes('JUZGADO') || n.includes('EMBARGO') || n.includes('PROCURADURIA') || n.includes('PGR') || n.includes('MULTA')) return 3;
  if (n.includes('ALCALDIA') || n.includes('MH ') || n.includes('ISSS') || n.includes('FSV') || n.includes('VIALIDAD')) return 2;
  return 5; // Otro
}

function log(msg) { console.log(`[${new Date().toISOString()}] ${msg}`); }

// ─── Main ─────────────────────────────────────────────────────────────────────

async function main() {
  log(`Iniciando sync ODD Brilo → core_db.ordenes_descuento${DRY_RUN ? ' (DRY RUN)' : ''}`);

  // Conectar
  const brilo = await sql.connect(briloCfg);
  log('Conectado a Brilo olNomina');

  const pg = new Pool(pgCfg);
  log('Conectado a core_db');

  // ── 1. Cargar mapa codigo → id de empleados activos en core_db ───────────────
  const { rows: empRows } = await pg.query(
    `SELECT id, codigo FROM empleados WHERE activo = true`
  );
  const empMap = {};
  for (const r of empRows) empMap[String(r.codigo).trim()] = r.id;
  log(`Empleados activos en core_db: ${empRows.length}`);

  // ── 2. Cargar acreedores existentes (por nombre normalizado) ─────────────────
  const { rows: acreedorRows } = await pg.query(
    `SELECT id, nombre FROM acreedores WHERE activo = true`
  );
  // índice por nombre normalizado (lowercase + sin acentos para match tolerante)
  const acreedorPorNombre = {};
  for (const r of acreedorRows) {
    acreedorPorNombre[r.nombre.toLowerCase()] = r.id;
    acreedorPorNombre[normalize(r.nombre)]    = r.id;
  }
  log(`Acreedores en core_db: ${acreedorRows.length}`);

  // ── 3. Cargar ODD Brilo existentes en ordenes_descuento (marcadas BRILO:) ────
  const { rows: existRows } = await pg.query(
    `SELECT id, empleado_id, notas, monto_q1, monto_q2, fecha_inicio, fecha_fin, estado_id
     FROM ordenes_descuento
     WHERE notas LIKE '[BRILO:%]'`
  );
  const existIdx = {};
  for (const r of existRows) {
    const m = r.notas?.match(/\[BRILO:(\d+)\]/);
    if (m) existIdx[m[1]] = r;
  }
  log(`ODD con marca BRILO ya en core_db: ${existRows.length}`);

  // ── 4. Leer préstamos activos de Brilo ────────────────────────────────────────
  const briloRes = await brilo.request().query(`
    SELECT
      prsn.prsnId,
      prsn.prsnNumero                       AS referencia,
      e.empCodigo,
      tpsn.tpsnNombre                       AS tipo_acreedor,
      prsn.prsnMontoPendienteALiquidar      AS monto_pendiente,
      q1.tppnMontoCuota                     AS monto_q1,
      q2.tppnMontoCuota                     AS monto_q2,
      CONVERT(date, COALESCE(q1.tppnFechaDesde, q2.tppnFechaDesde))   AS fecha_inicio,
      CONVERT(date, COALESCE(q1.tppnFechaHasta, q2.tppnFechaHasta))   AS fecha_fin
    FROM olNomina.dbo.PrestamosNmn prsn
    JOIN olComun.dbo.Empleados e          ON prsn.empId   = e.empId
    JOIN olNomina.dbo.TiposPrestamosNmn tpsn ON prsn.tpsnId = tpsn.tpsnId
    LEFT JOIN olNomina.dbo.TiposPlaXPrestamoNmn q1
      ON q1.prsnId = prsn.prsnId AND q1.tplId = 1
    LEFT JOIN olNomina.dbo.TiposPlaXPrestamoNmn q2
      ON q2.prsnId = prsn.prsnId AND q2.tplId = 2
    WHERE prsn.prsnLiquidado = 0
      AND prsn.prsnGenerado  = 1
      AND e.empActivo        = 1
    ORDER BY e.empCodigo, prsn.prsnId
  `);

  const prestamos = briloRes.recordset;
  log(`Préstamos activos en Brilo: ${prestamos.length}`);
  await brilo.close();

  // ── 5. Procesar ───────────────────────────────────────────────────────────────
  let inserted = 0, updated = 0, sinCambio = 0, noEmp = 0, sinMonto = 0, acreedoresCreados = 0;
  const now = new Date();

  for (const p of prestamos) {
    // Mapear empleado
    const empleadoId = empMap[String(p.empCodigo).trim()];
    if (!empleadoId) { noEmp++; continue; }

    // Validar que tenga al menos un monto
    const montoQ1 = parseFloat(p.monto_q1) || 0;
    const montoQ2 = parseFloat(p.monto_q2) || 0;
    if (montoQ1 <= 0 && montoQ2 <= 0) { sinMonto++; continue; }

    // Mapear/crear acreedor
    const tipoKey = (p.tipo_acreedor || '').trim();
    const acreedorDef = ACREEDOR_MAP[tipoKey] ?? {
      nombre: p.tipo_acreedor,
      tipo:   inferirTipo(p.tipo_acreedor),
    };
    // Buscar por nombre exacto o normalizado (sin acentos/case)
    const nomNorm     = acreedorDef.nombre.toLowerCase();
    const nomNormStrip = normalize(acreedorDef.nombre);

    let acreedorId = acreedorPorNombre[nomNorm] ?? acreedorPorNombre[nomNormStrip];
    if (!acreedorId) {
      if (!DRY_RUN) {
        const ins = await pg.query(
          `INSERT INTO acreedores (tipo_acreedor_id, nombre, activo, aud_usuario, created_at, updated_at)
           VALUES ($1, $2, true, 'sync_brilo', $3, $3) RETURNING id`,
          [acreedorDef.tipo, acreedorDef.nombre, now]
        );
        acreedorId = ins.rows[0].id;
      } else {
        acreedorId = -1; // placeholder para dry-run
      }
      // Actualizar índice en memoria (también en dry-run para deduplicar)
      acreedorPorNombre[nomNorm]      = acreedorId;
      acreedorPorNombre[nomNormStrip] = acreedorId;
      log(`  ACREEDOR NUEVO: "${acreedorDef.nombre}" (tipo=${acreedorDef.tipo})${DRY_RUN ? ' [dry]' : ' → id=' + acreedorId}`);
      acreedoresCreados++;
    }

    const fi = p.fecha_inicio ? new Date(p.fecha_inicio).toISOString().slice(0, 10) : null;
    const ff = p.fecha_fin    ? new Date(p.fecha_fin).toISOString().slice(0, 10)    : null;
    const ref = p.referencia ? String(p.referencia).trim() : null;
    const notas = `[BRILO:${p.prsnId}]`;
    const prsnIdStr = String(p.prsnId);

    const existing = existIdx[prsnIdStr];

    if (!existing) {
      if (!DRY_RUN) {
        await pg.query(
          `INSERT INTO ordenes_descuento
            (empleado_id, acreedor_id, estado_id, monto_q1, monto_q2,
             referencia, fecha_inicio, fecha_fin, notas, aud_usuario, created_at, updated_at)
           VALUES ($1,$2,1,$3,$4,$5,$6,$7,$8,'sync_brilo',$9,$9)`,
          [empleadoId, acreedorId, montoQ1, montoQ2, ref, fi, ff, notas, now]
        );
      }
      log(`  INSERT emp=${p.empCodigo} acreedor="${acreedorDef.nombre}" Q1=$${montoQ1} Q2=$${montoQ2} ref=${ref || '-'}`);
      inserted++;
    } else {
      const changed =
        parseFloat(existing.monto_q1 ?? 0) !== montoQ1 ||
        parseFloat(existing.monto_q2 ?? 0) !== montoQ2 ||
        (existing.fecha_fin?.toISOString?.().slice(0, 10) ?? null) !== ff;

      if (changed) {
        if (!DRY_RUN) {
          await pg.query(
            `UPDATE ordenes_descuento
             SET monto_q1=$1, monto_q2=$2, fecha_fin=$3, estado_id=1, updated_at=$4
             WHERE id=$5`,
            [montoQ1, montoQ2, ff, now, existing.id]
          );
        }
        log(`  UPDATE id=${existing.id} emp=${p.empCodigo} Q1=$${montoQ1} Q2=$${montoQ2}`);
        updated++;
      } else {
        sinCambio++;
      }
      delete existIdx[prsnIdStr];
    }
  }

  // ── 6. Resumen ────────────────────────────────────────────────────────────────
  log('');
  log('═══════════════════════════════════════════════════');
  log(`Resumen${DRY_RUN ? ' (DRY RUN — sin cambios reales)' : ''}:`);
  log(`  Acreedores creados: ${acreedoresCreados}`);
  log(`  Insertados:         ${inserted}`);
  log(`  Actualizados:       ${updated}`);
  log(`  Sin cambio:         ${sinCambio}`);
  log(`  Sin emp en RDS:     ${noEmp}`);
  log(`  Sin monto (Q1+Q2):  ${sinMonto}`);
  log('═══════════════════════════════════════════════════');

  await pg.end();
}

main().catch(e => { console.error('ERROR FATAL:', e.message); process.exit(1); });
