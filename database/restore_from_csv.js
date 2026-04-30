/**
 * restore_from_csv.js
 *
 * Restaura ingredientes de sub-recetas a partir del CSV de backup
 * exportado esta mañana desde BRILO antes del sync masivo.
 *
 * Solo actúa sobre sub-recetas que actualmente tienen 0 ingredientes en PG.
 *
 * Uso: node restore_from_csv.js [--dry-run]
 */

const fs   = require('fs');
const path = require('path');
const { Pool } = require('pg');

const CSV_PATH = path.join(__dirname, 'INV_backup_esta_maniana.csv');

const pgConfig = {
  host:     'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port:     5432,
  database: 'compras_db',
  user:     'cadejo_admin',
  password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
  connectionTimeoutMillis: 30000,
};

const DRY_RUN = process.argv.includes('--dry-run');

// Código de presentación → unidad
const PRES_UNIT_MAP = {
  'OZ001':   'oz',
  'PORCION': 'porcion',
  'UNIDAD':  'u',
  'LB001':   'lb',
  'KG001':   'kg',
  'LT001':   'lt',
  'GR001':   'gr',
  'ML001':   'ml',
  'BOTELLA': 'botella',
};
const presToUnit = code =>
  !code ? 'u' : (PRES_UNIT_MAP[code.trim().toUpperCase()] ?? code.trim().toLowerCase().slice(0, 20));

const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s => console.log(`[${ts()}] ${s}`);

// ── Parsear CSV ──────────────────────────────────────────────────────────────
function parseCsv(filePath) {
  const lines = fs.readFileSync(filePath, 'utf8').split(/\r?\n/);
  // Col A=0 CódPadrE, B=1 CódMP, C=2 CantMP, F=6 CódPresentación, G=7 CantPresentación
  const groups = {}; // codigoPadre → [{mpCodigo, cantidadMp, codPresentacion, cantPresent}]

  for (let i = 1; i < lines.length; i++) {
    const line = lines[i].trim();
    if (!line) continue;
    const cols = line.split(',');
    const padre   = (cols[0] ?? '').trim();
    const mp      = (cols[1] ?? '').trim();
    const cantMp  = parseFloat((cols[2] ?? '').trim()) || null;
    const activo  = (cols[3] ?? '').trim().toUpperCase();
    const codPres = (cols[6] ?? '').trim();
    const cantPres = parseFloat((cols[7] ?? '').trim()) || null;

    if (!padre || !mp || activo !== 'SI') continue;

    if (!groups[padre]) groups[padre] = [];
    groups[padre].push({ mpCodigo: mp, cantidadMp: cantMp, codPresentacion: codPres, cantPresent: cantPres });
  }
  return groups;
}

async function main() {
  log('================================================');
  log('RESTORE SUB-RECETA INGREDIENTES DESDE CSV');
  log(DRY_RUN ? '*** DRY RUN ***' : '*** MODO REAL ***');
  log('================================================\n');

  // ── Parsear CSV ─────────────────────────────────────────────────────────────
  log('[0] Parseando CSV...');
  const csvGroups = parseCsv(CSV_PATH);
  const csvCodigos = Object.keys(csvGroups);
  log(`    ${csvCodigos.length} padres distintos en el CSV.`);

  // ── Conectar PG ──────────────────────────────────────────────────────────────
  log('\n[1] Conectando PostgreSQL...');
  const pool = new Pool(pgConfig);
  const pg   = await pool.connect();
  log('    PostgreSQL OK\n');

  const now = new Date().toISOString();

  // ── 1. Sub-recetas con 0 ingredientes en PG ──────────────────────────────────
  log('[2] Cargando sub-recetas con 0 ingredientes...');
  const sinIngr = (await pg.query(`
    SELECT r.id, r.codigo_origen, r.nombre, r.modificado_localmente
    FROM recetas r
    LEFT JOIN receta_ingredientes ri ON ri.receta_id = r.id
    WHERE r.tipo_receta = 'sub_receta'
      AND r.activa = true
      AND r.codigo_origen IS NOT NULL AND r.codigo_origen != ''
    GROUP BY r.id, r.codigo_origen, r.nombre, r.modificado_localmente
    HAVING COUNT(ri.id) = 0
    ORDER BY r.codigo_origen
  `)).rows;

  log(`    ${sinIngr.length} sub-recetas con 0 ingredientes en PG.`);

  if (sinIngr.length === 0) {
    log('\nNo hay sub-recetas sin ingredientes. Nada que restaurar.');
    pg.release(); await pool.end(); return;
  }

  // Filtrar las que tienen datos en el CSV
  const candidatos = sinIngr.filter(r => csvGroups[String(r.codigo_origen).trim()]);
  const sinDatosCsv = sinIngr.filter(r => !csvGroups[String(r.codigo_origen).trim()]);

  log(`    ${candidatos.length} tienen datos en el CSV.`);
  log(`    ${sinDatosCsv.length} NO tienen datos en el CSV (no se pueden restaurar desde aquí):`);
  sinDatosCsv.forEach(r => log(`      - ${r.codigo_origen} | ${r.nombre}`));

  if (candidatos.length === 0) {
    log('\nNinguna sub-receta sin ingredientes tiene datos en el CSV.');
    pg.release(); await pool.end(); return;
  }

  // ── 2. Cargar mapa codigo_origen → receta_id (para sub-recetas como ingredientes) ──
  log('\n[3] Cargando mapa recetas (para sub-recetas usadas como ingrediente)...');
  const allMpCodigos = [...new Set(
    candidatos.flatMap(r => (csvGroups[r.codigo_origen] ?? []).map(x => x.mpCodigo))
  )];

  // Buscar en recetas (sub-recetas como ingrediente)
  const recetaMap = {}; // codigo_origen → receta.id
  for (let i = 0; i < allMpCodigos.length; i += 500) {
    const chunk = allMpCodigos.slice(i, i + 500);
    const res = await pg.query(
      `SELECT id, codigo_origen FROM recetas
       WHERE codigo_origen = ANY($1::text[]) AND tipo_receta = 'sub_receta'`,
      [chunk]
    );
    res.rows.forEach(r => { recetaMap[r.codigo_origen] = r.id; });
  }
  log(`    ${Object.keys(recetaMap).length} sub-recetas mapeadas como ingredientes potenciales.`);

  // Buscar en productos (ingredientes crudos)
  log('[4] Cargando mapa productos (ingredientes crudos)...');
  const prodMap = {}; // codigo → {id, unidad}
  for (let i = 0; i < allMpCodigos.length; i += 500) {
    const chunk = allMpCodigos.slice(i, i + 500);
    const res = await pg.query(
      `SELECT id, codigo, unidad FROM productos WHERE codigo = ANY($1::text[])`,
      [chunk]
    );
    res.rows.forEach(r => { prodMap[r.codigo] = { id: r.id, unidad: r.unidad }; });
  }
  log(`    ${Object.keys(prodMap).length} productos mapeados.`);

  // ── 3. Insertar ingredientes ──────────────────────────────────────────────────
  log('\n[5] Insertando ingredientes desde CSV...');

  let totalInserted = 0;
  let totalSkipped  = 0;
  const noEncontrados = new Set();

  for (const receta of candidatos) {
    const codigo = String(receta.codigo_origen).trim();
    const filas  = csvGroups[codigo] ?? [];
    const rows   = [];

    for (const fila of filas) {
      const mp = fila.mpCodigo;
      let productoId   = null;
      let subRecetaId  = null;
      let cantidad     = null;
      let unidad       = 'u';

      // ¿Es sub-receta como ingrediente? (tiene código presentación)
      if (fila.codPresentacion && recetaMap[mp]) {
        subRecetaId = recetaMap[mp];
        cantidad    = fila.cantPresent ?? 1;
        unidad      = presToUnit(fila.codPresentacion);
      } else if (prodMap[mp]) {
        // Ingrediente crudo
        productoId = prodMap[mp].id;
        cantidad   = fila.cantidadMp ?? 1;
        unidad     = prodMap[mp].unidad ?? 'u';
        if (!unidad || unidad.trim() === '') unidad = 'u';
      } else {
        // No encontrado en ninguna tabla
        noEncontrados.add(mp);
        totalSkipped++;
        continue;
      }

      if (!cantidad || cantidad <= 0) {
        log(`    WARN: cantidad 0 o nula para ${mp} en ${codigo}, se usa 1`);
        cantidad = 1;
      }

      rows.push({
        receta_id:            receta.id,
        producto_id:          productoId,
        sub_receta_id:        subRecetaId,
        cantidad_por_plato:   cantidad,
        unidad:               String(unidad).slice(0, 20),
        aud_usuario:          'restore_csv',
        created_at:           now,
        updated_at:           now,
      });
    }

    if (rows.length === 0) {
      log(`    SKIP ${codigo}: ningún ingrediente mapeado.`);
      continue;
    }

    if (!DRY_RUN) {
      const cols = ['receta_id','producto_id','sub_receta_id','cantidad_por_plato','unidad','aud_usuario','created_at','updated_at'];
      const params = [];
      const rowParts = rows.map(row => {
        const ph = cols.map(col => {
          params.push(row[col] ?? null);
          return `$${params.length}`;
        });
        return `(${ph.join(',')})`;
      });
      await pg.query(
        `INSERT INTO receta_ingredientes (${cols.join(',')}) VALUES ${rowParts.join(',')}`,
        params
      );
    }

    totalInserted += rows.length;
    log(`    [${DRY_RUN ? 'DRY' : 'OK'}] ${codigo} (${receta.nombre?.slice(0,40)}): ${rows.length} ingredientes.`);
  }

  if (noEncontrados.size > 0) {
    log(`\n    Códigos MP no encontrados en productos ni recetas (${noEncontrados.size}):`);
    [...noEncontrados].forEach(c => log(`      - ${c}`));
  }

  log('\n================================================');
  log('RESUMEN:');
  log(`  Sub-recetas candidatas:         ${candidatos.length}`);
  log(`  Ingredientes insertados:        ${totalInserted}`);
  log(`  Ingredientes no mapeados:       ${totalSkipped}`);
  log(DRY_RUN ? '\nDRY-RUN OK. Ejecuta sin --dry-run para insertar.' : '\n✓ Restauración completada.');
  log('================================================');

  pg.release();
  await pool.end();
}

main().catch(err => {
  console.error('\nERROR:', err.message, err.stack);
  process.exit(1);
});
