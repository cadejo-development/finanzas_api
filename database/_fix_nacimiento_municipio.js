/**
 * _fix_nacimiento_municipio.js
 *
 * Migración puntual: parsea lugar_nacimiento en expediente_datos_personales
 * y llena nacimiento_municipio_id donde se identifique el municipio.
 *
 * Casos manejados:
 *   "Ilopango, San Salvador"          → municipio=Ilopango, dept=San Salvador
 *   "San Salvador"                    → municipio=San Salvador
 *   "Tepecoyo la libertad"            → municipio=Tepecoyo (sin coma)
 *   "San Salvador / San Salvador"     → slash como separador
 *   "San José Villanueva la libertad barrio..." → match por prefijo
 *
 * Uso:
 *   node _fix_nacimiento_municipio.js             (aplica)
 *   node _fix_nacimiento_municipio.js --dry-run   (solo muestra resultados)
 */

const { Pool } = require('pg');

const PG_CORE = { host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432, database: 'core_db', user: 'cadejo_admin', password: 'Holamundo#3..', ssl: { rejectUnauthorized: false } };
const PG_RRHH = { host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432, database: 'rrhh_db', user: 'cadejo_admin', password: 'Holamundo#3..', ssl: { rejectUnauthorized: false } };

const DRY_RUN = process.argv.includes('--dry-run');
const NOW     = new Date().toISOString();
const ts      = () => new Date().toTimeString().slice(0, 8);
const log     = s  => console.log(`[${ts()}] ${s}`);

const DEPTO_ALIAS = {
  'SS': 'SAN SALVADOR', 'S S': 'SAN SALVADOR', 'SAN SALV': 'SAN SALVADOR',
  'USULL': 'USULUTAN', 'USULUTAN': 'USULUTAN',
  'LA LIB': 'LA LIBERTAD', 'LALIBERTAD': 'LA LIBERTAD',
  'STA ANA': 'SANTA ANA', 'S ANA': 'SANTA ANA',
  'CHAL': 'CHALATENANGO',
  'SAN VIC': 'SAN VICENTE',
  'CABANAS': 'CABANAS',
  'LA UNION': 'LA UNION',
  'MORAZAN': 'MORAZAN',
};

function norm(s) {
  return (s || '').toUpperCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^A-Z0-9\s]/g, ' ').replace(/\s+/g, ' ').trim();
}

function parsearMunicipio(texto, municipios) {
  if (!texto) return null;
  const t = norm(texto);
  if (!t) return null;

  // Determinar separador: coma o slash
  const sepIdx = t.includes(',') ? t.indexOf(',') : (t.includes('/') ? t.indexOf('/') : -1);

  let parteMuni = sepIdx > -1 ? t.slice(0, sepIdx).trim() : t;
  let parteDepto = sepIdx > -1 ? t.slice(sepIdx + 1).trim() : '';

  const deptoNorm = DEPTO_ALIAS[parteDepto] ?? parteDepto;

  // ── 1. Coincidencia exacta en el nombre del municipio ────────────────────
  let cands = municipios.filter(m => norm(m.municipio) === parteMuni);
  if (cands.length === 1) return cands[0];
  if (cands.length > 1) {
    // Desambiguar por departamento
    if (deptoNorm) {
      const m = cands.find(c => norm(c.departamento).startsWith(deptoNorm.slice(0, 5)));
      if (m) return m;
    }
    return cands[0]; // primer match si no hay info de depto
  }

  // ── 2. Sin separador: intentar prefijos de palabras ──────────────────────
  //    "Tepecoyo la libertad" → probar "Tepecoyo", "Tepecoyo La", "Tepecoyo La Libertad"
  if (sepIdx === -1 && t.includes(' ')) {
    const words = t.split(' ');
    for (let len = words.length - 1; len >= 1; len--) {
      const candidate = words.slice(0, len).join(' ');
      const depGuess  = words.slice(len).join(' ');
      const cs = municipios.filter(m => norm(m.municipio) === candidate);
      if (cs.length === 1) return cs[0];
      if (cs.length > 1 && depGuess) {
        const m = cs.find(c => norm(c.departamento).startsWith(depGuess.slice(0, 4)));
        if (m) return m;
        return cs[0];
      }
    }
  }

  // ── 3. Match por prefijo del texto (addresses largas) ────────────────────
  //    "San José Villanueva la libertad barrio..." → prefijo "San José Villanueva"
  const sorted = [...municipios].sort((a, b) => b.municipio.length - a.municipio.length);
  for (const m of sorted) {
    const mn = norm(m.municipio);
    if (mn.length >= 5 && (t.startsWith(mn + ' ') || t === mn)) return m;
  }

  return null;
}

async function run() {
  if (DRY_RUN) log('=== MODO DRY-RUN ===\n');

  const pgCore = new Pool(PG_CORE);
  const pgRrhh = new Pool(PG_RRHH);
  await Promise.all([pgCore.query('SELECT 1'), pgRrhh.query('SELECT 1')]);
  log('PostgreSQL OK.\n');

  try {
    // Cargar municipios
    const { rows: munRows } = await pgCore.query(
      'SELECT m.id, m.nombre AS municipio, d.nombre AS departamento FROM geo_municipios m JOIN geo_departamentos d ON d.id = m.departamento_id'
    );
    log(`Municipios cargados: ${munRows.length}`);

    // Registros con texto pero sin municipio_id
    const { rows: pendientes } = await pgRrhh.query(
      `SELECT id, empleado_id, lugar_nacimiento
       FROM expediente_datos_personales
       WHERE nacimiento_municipio_id IS NULL
         AND lugar_nacimiento IS NOT NULL
         AND lugar_nacimiento != ''
       ORDER BY id`
    );
    log(`Registros pendientes: ${pendientes.length}\n`);

    let ok = 0, sinMatch = 0;
    const sinMatchLog = [];

    for (const row of pendientes) {
      const match = parsearMunicipio(row.lugar_nacimiento, munRows);

      if (DRY_RUN) {
        if (match) {
          log(`  ✓ emp_id=${row.empleado_id}  "${row.lugar_nacimiento}"  →  ${match.municipio}, ${match.departamento}  (id=${match.id})`);
          ok++;
        } else {
          sinMatchLog.push(`  ✗ emp_id=${row.empleado_id}  "${row.lugar_nacimiento}"`);
          sinMatch++;
        }
        continue;
      }

      if (match) {
        await pgRrhh.query(
          `UPDATE expediente_datos_personales
           SET nacimiento_municipio_id = $1,
               lugar_nacimiento        = NULL,
               updated_at              = $2
           WHERE id = $3`,
          [match.id, NOW, row.id]
        );
        ok++;
      } else {
        sinMatch++;
        sinMatchLog.push(`  ✗ emp_id=${row.empleado_id}  "${row.lugar_nacimiento}"`);
      }
      process.stdout.write(`\r  Procesados: ${ok + sinMatch}/${pendientes.length} `);
    }
    if (!DRY_RUN) console.log();

    log('\n══════════════════════════════════');
    log(`Actualizados con municipio_id: ${ok}`);
    log(`Sin match (quedan como texto):  ${sinMatch}`);
    if (sinMatchLog.length) {
      log('\nRegistros sin match:');
      sinMatchLog.forEach(s => log(s));
    }
    log('══════════════════════════════════');

  } finally {
    await Promise.all([pgCore.end(), pgRrhh.end()]).catch(() => {});
    log('Conexiones cerradas.');
  }
}

run().catch(err => { console.error('ERROR:', err.message ?? err); process.exit(1); });
