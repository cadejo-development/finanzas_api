/**
 * registrar_bajas_mayo2026.js
 *
 * Registra desvinculaciones del CSV de bajas mayo 2026 en rrhh_db.
 * - Busca al empleado por código en core_db
 * - Si ya tiene desvinculación registrada → la omite
 * - Inserta con estado 'aprobado' (registro administrativo retroactivo)
 *
 * Motivos asignados por tipo:
 *   abandono → tipo='despido',  motivo_id=5 (Abandono de Puesto)
 *   despido  → tipo='despido',  motivo_id=3 (Mala Conducta) — genérico
 *   renuncia → tipo='renuncia', motivo_id=6 (Razones Personales)
 *
 * Uso:
 *   node registrar_bajas_mayo2026.js             (ejecuta)
 *   node registrar_bajas_mayo2026.js --dry-run   (sin cambios)
 */

const { Pool } = require('pg');

const PG_CORE = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'core_db', user: 'cadejo_admin', password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
};
const PG_RRHH = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'rrhh_db', user: 'cadejo_admin', password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
};

const DRY_RUN = process.argv.includes('--dry-run');
const NOW     = new Date().toISOString();
const AUD     = 'registrar_bajas_mayo2026.js';
const ts      = () => new Date().toTimeString().slice(0, 8);
const log     = s  => console.log(`[${ts()}] ${s}`);

// ── CSV de bajas mayo 2026 ────────────────────────────────────────────────────
// tipo_csv: abandono | despido | renuncia
const BAJAS = [
  { codigo: '1556', nombres: 'GABRIEL ENOC',        apellidos: 'MATUTE ROSALES',       fecha_egreso: '2026-05-04', tipo_csv: 'abandono' },
  { codigo: '2494', nombres: 'NEFTALI ALEXANDER',   apellidos: 'GUILLEN MARTINEZ',     fecha_egreso: '2026-05-07', tipo_csv: 'abandono' },
  { codigo: '2516', nombres: 'KARLA SABRINA',        apellidos: 'OLAIZOLA MUNGUIA',     fecha_egreso: '2026-05-07', tipo_csv: 'despido'  },
  { codigo: '2202', nombres: 'ALISSON LILIANA',      apellidos: 'ECHEGOYEN ORELLANA',   fecha_egreso: '2026-05-10', tipo_csv: 'renuncia' },
  { codigo: '2521', nombres: 'LEZLY PAOLA',          apellidos: 'ROJAS MELARA',         fecha_egreso: '2026-05-12', tipo_csv: 'renuncia' },
  { codigo: '2192', nombres: 'BRIAN ADONAY',         apellidos: 'LOPEZ CRUZ',           fecha_egreso: '2026-05-15', tipo_csv: 'despido'  },
  { codigo: '2523', nombres: 'JOSE OBDULIO',         apellidos: 'PALACIOS GOMEZ',       fecha_egreso: '2026-05-15', tipo_csv: 'abandono' },
  { codigo: '2544', nombres: 'OSCAR FERNANDO',       apellidos: 'SANCHEZ CATALAN',      fecha_egreso: '2026-05-15', tipo_csv: 'abandono' },
  { codigo: '2420', nombres: 'ADA MIRSA',            apellidos: 'SANCHEZ MEJIA',        fecha_egreso: '2026-05-17', tipo_csv: 'renuncia' },
  { codigo: '2497', nombres: 'JOSE MARIO',           apellidos: 'GONZALEZ DIAZ',        fecha_egreso: '2026-05-17', tipo_csv: 'renuncia' },
  { codigo: '2082', nombres: 'RAUL ARMANDO',         apellidos: 'PARADA RODRIGUEZ',     fecha_egreso: '2026-05-18', tipo_csv: 'despido'  },
  { codigo: '2532', nombres: 'RAFAEL ANTONIO',       apellidos: 'AGUILAR CONTRERAS',    fecha_egreso: '2026-05-19', tipo_csv: 'abandono' },
  { codigo: '1927', nombres: 'GABRIELA ESTEFANIA',   apellidos: 'VALLADARES MORAN',     fecha_egreso: '2026-05-20', tipo_csv: 'renuncia' },
  { codigo: '2553', nombres: 'MEYBELINE',            apellidos: 'PABLO YANES',          fecha_egreso: '2026-05-22', tipo_csv: 'abandono' },
  { codigo: '1674', nombres: 'ISAIAS MAURICIO',      apellidos: 'MELARA RAMIREZ',       fecha_egreso: '2026-05-23', tipo_csv: 'renuncia' },
  { codigo: '2385', nombres: 'MILTON ALEXANDER',     apellidos: 'GOMEZ GOMEZ',          fecha_egreso: '2026-05-24', tipo_csv: 'renuncia' },
  { codigo: '2565', nombres: 'RENE DANILO',          apellidos: 'ARGUERA CALDERON',     fecha_egreso: '2026-05-24', tipo_csv: 'abandono' },
  { codigo: '2469', nombres: 'AILYN MARCELLA',       apellidos: 'MEJIA MERLOS',         fecha_egreso: '2026-05-25', tipo_csv: 'despido'  },
  { codigo: '2541', nombres: 'CHRISTIAN ALEJANDRO',  apellidos: 'ZAVALA HENRIQUEZ',     fecha_egreso: '2026-05-25', tipo_csv: 'despido'  },
  { codigo: '2321', nombres: 'JOSE RAUL',            apellidos: 'MENDEZ ESTRADA',       fecha_egreso: '2026-05-29', tipo_csv: 'despido'  },
  { codigo: '2547', nombres: 'DIEGO ALFONSO',        apellidos: 'PRIETO ALFEREZ',       fecha_egreso: '2026-05-31', tipo_csv: 'despido'  },
  { codigo: '2370', nombres: 'WILFREDO',             apellidos: 'AGUILAR MEJIA',        fecha_egreso: '2026-05-31', tipo_csv: 'despido'  },
];

// Mapeo CSV → DB
const TIPO_MAP = {
  abandono: { tipo: 'despido',  motivo_id: 5 },  // Abandono de Puesto
  despido:  { tipo: 'despido',  motivo_id: 3 },  // Mala Conducta (genérico)
  renuncia: { tipo: 'renuncia', motivo_id: 6 },  // Razones Personales
};

// ─────────────────────────────────────────────────────────────────────────────
async function run() {
  if (DRY_RUN) log('=== MODO DRY-RUN: sin cambios en BD ===\n');

  const pgCore = new Pool(PG_CORE);
  const pgRrhh = new Pool(PG_RRHH);
  await Promise.all([pgCore.query('SELECT 1'), pgRrhh.query('SELECT 1')]);
  log('PostgreSQL OK.\n');

  try {
    // 1. Mapear código → empleado (id, nombre, cargo, sucursal)
    const codigos = BAJAS.map(b => b.codigo);
    const { rows: empRows } = await pgCore.query(`
      SELECT e.id, e.codigo,
             CONCAT(e.nombres, ' ', e.apellidos) AS nombre_completo,
             c.nombre AS cargo,
             s.nombre AS sucursal
      FROM empleados e
      LEFT JOIN cargos c    ON c.id = e.cargo_id
      LEFT JOIN sucursales s ON s.id = e.sucursal_id
      WHERE e.codigo = ANY($1)
    `, [codigos]);
    const empPorCodigo = Object.fromEntries(empRows.map(r => [r.codigo, r]));
    log(`Empleados encontrados en core_db: ${empRows.length}/${BAJAS.length}`);

    // 2. Verificar desvinculaciones ya existentes
    const empIds = empRows.map(r => r.id);
    const { rows: yaExisten } = await pgRrhh.query(
      `SELECT empleado_id FROM desvinculaciones WHERE empleado_id = ANY($1)`,
      [empIds]
    );
    const yaRegistrados = new Set(yaExisten.map(r => r.empleado_id));
    log(`Ya tienen desvinculación registrada: ${yaRegistrados.size}\n`);

    // 3. Calcular inserciones
    const insertar = [];
    const omitidos = [];
    const sinMatch = [];

    for (const baja of BAJAS) {
      const emp = empPorCodigo[baja.codigo];
      if (!emp) { sinMatch.push(baja.codigo); continue; }

      if (yaRegistrados.has(emp.id)) {
        omitidos.push(`${baja.codigo} ${baja.apellidos}, ${baja.nombres}`);
        continue;
      }

      const map = TIPO_MAP[baja.tipo_csv];
      insertar.push({
        empleado_id:    emp.id,
        tipo:           map.tipo,
        motivo_id:      map.motivo_id,
        fecha_efectiva: baja.fecha_egreso,
        empleado_nombre: emp.nombre_completo,
        cargo_nombre:   emp.cargo,
        sucursal_nombre: emp.sucursal,
        observaciones:  `Registro retroactivo — baja mayo 2026 (${baja.tipo_csv})`,
        estado:         'aprobado',
      });
    }

    log('──────────────────────────────────────────');
    log(`A INSERTAR:  ${insertar.length}`);
    log(`Ya existían: ${omitidos.length}`);
    log(`Sin match:   ${sinMatch.length}`);
    if (sinMatch.length) log(`  Códigos sin match: ${sinMatch.join(', ')}`);
    log('──────────────────────────────────────────\n');

    if (omitidos.length) {
      log('Omitidos (ya registrados):');
      omitidos.forEach(n => log(`  • ${n}`));
      console.log();
    }

    log('A insertar:');
    insertar.forEach(r => log(`  [${r.tipo.toUpperCase()}] ${r.empleado_nombre} — ${r.fecha_efectiva}`));
    console.log();

    if (DRY_RUN) {
      log('(dry-run) Sin cambios. Quita --dry-run para aplicar.');
      return;
    }

    // 4. Insertar
    let ok = 0;
    for (const r of insertar) {
      await pgRrhh.query(`
        INSERT INTO desvinculaciones
          (empleado_id, procesado_por_id, tipo, motivo_id, fecha_efectiva,
           empleado_nombre, cargo_nombre, sucursal_nombre, observaciones,
           estado, aud_usuario, created_at, updated_at)
        VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$12)
      `, [
        r.empleado_id, 127, r.tipo, r.motivo_id, r.fecha_efectiva,
        r.empleado_nombre, r.cargo_nombre, r.sucursal_nombre,
        r.observaciones, r.estado, AUD, NOW
      ]);
      ok++;
      process.stdout.write(`\r  Insertadas: ${ok}/${insertar.length} `);
    }
    if (insertar.length) console.log();

    log(`\n✓ ${ok} desvinculaciones registradas.`);

  } finally {
    await Promise.all([pgCore.end(), pgRrhh.end()]).catch(() => {});
    log('Conexiones cerradas.');
  }
}

run().catch(err => {
  console.error('\nERROR:', err.message ?? err);
  process.exit(1);
});
