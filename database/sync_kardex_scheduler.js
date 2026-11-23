/**
 * sync_kardex_scheduler.js
 *
 * Lee kardex-periodos.json y ejecuta sync_brilo_kardex.js para cada
 * sucursal cuya fecha de conteo (hasta) coincida con hoy (o --fecha).
 *
 * Uso: node database/sync_kardex_scheduler.js [--fecha=YYYY-MM-DD] [--sucursal=ID] [--dry-run]
 *
 * --fecha     Fecha a tratar como "hoy" (default: fecha actual UTC)
 * --sucursal  Forzar una sucursal específica (ignora la comparación de fecha)
 * --dry-run   Pasa --dry-run a sync_brilo_kardex.js
 */

require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const { spawnSync } = require('child_process');
const path = require('path');
const fs   = require('fs');

// ── Argumentos ────────────────────────────────────────────────────────────────
const rawArgs = process.argv.slice(2);
const argMap  = Object.fromEntries(
  rawArgs.filter(a => a.startsWith('--') && a.includes('=')).map(a => {
    const [k, ...v] = a.slice(2).split('=');
    return [k, v.join('=')];
  })
);

const DRY_RUN     = rawArgs.includes('--dry-run');
const FECHA_HOY   = argMap.fecha ?? new Date().toISOString().slice(0, 10);
const SUCURSAL_ID = argMap.sucursal ? parseInt(argMap.sucursal) : null;

// ── Cargar períodos ───────────────────────────────────────────────────────────
const PERIODOS_PATH = path.join(__dirname, 'kardex-periodos.json');
let periodos;
try {
  periodos = JSON.parse(fs.readFileSync(PERIODOS_PATH, 'utf8'));
} catch (err) {
  console.error(`No se pudo leer kardex-periodos.json: ${err.message}`);
  process.exit(1);
}

// En modo automático (sin --fecha) solo ejecuta períodos cuyo hasta = AYER,
// garantizando que el día de fin de período ya cerró completamente en Brilo.
const FECHA_AYER = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
const FECHA_BUSCAR = argMap.fecha ?? FECHA_AYER;

const targets = periodos.filter(p => {
  if (SUCURSAL_ID) return p.sucursal_id === SUCURSAL_ID;
  return p.hasta === FECHA_BUSCAR;
});

if (targets.length === 0) {
  console.log(`No hay sucursales programadas para ${FECHA_HOY}`);
  process.exit(0);
}

console.log(`\n=== Kardex Scheduler — buscando hasta=${FECHA_BUSCAR} (hoy=${FECHA_HOY}) ===`);
console.log(`Sucursales: ${targets.map(t => `${t.bod} (suc=${t.sucursal_id})`).join(', ')}\n`);

// ── Ejecutar por sucursal ─────────────────────────────────────────────────────
let globalExit = 0;
const SCRIPT    = path.join(__dirname, 'sync_brilo_kardex.js');
const REPO_ROOT = path.join(__dirname, '..');

for (const p of targets) {
  const nodeArgs = [
    SCRIPT,
    `--sucursal=${p.sucursal_id}`,
    `--hasta=${p.hasta}`,
    `--movs-hasta=${p.movs_hasta}`,
    `--desde=${p.desde}`,
  ];
  if (DRY_RUN) nodeArgs.push('--dry-run');

  console.log(`\n--- ${p.bod} ${p.nombre} ---`);
  console.log(`Ejecutando: node database/sync_brilo_kardex.js --sucursal=${p.sucursal_id} --hasta=${p.hasta} --movs-hasta=${p.movs_hasta} --desde=${p.desde}${DRY_RUN ? ' --dry-run' : ''}`);

  const result = spawnSync('node', nodeArgs, {
    stdio: 'inherit',
    cwd:   REPO_ROOT,
  });

  if (result.status !== 0 || result.error) {
    console.error(`\nERROR en ${p.bod}: exit ${result.status ?? result.error?.message}`);
    globalExit = 1;
  } else {
    console.log(`\n✓ ${p.bod} completado`);
  }
}

console.log(`\n=== Scheduler finalizado (exit ${globalExit}) ===`);
process.exit(globalExit);
