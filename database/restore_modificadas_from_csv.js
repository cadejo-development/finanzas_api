/**
 * restore_modificadas_from_csv.js
 *
 * Restaura los ingredientes de TODAS las sub-recetas con modificado_localmente=true
 * usando el CSV de backup exportado esta mañana desde BRILO.
 *
 * Las unidades de medida de ingredientes crudos se obtienen de SQL Server
 * (MaterialesXProducto.uniId → Unidades.uniNombre), no de productos.unidad en PG.
 * Para sub-recetas sin equivalente en SQL Server se usa productos.unidad como fallback.
 *
 * Uso: node restore_modificadas_from_csv.js [--dry-run]
 */

const fs   = require('fs');
const path = require('path');
const sql  = require('mssql');
const { Pool } = require('pg');

const CSV_PATH = path.join(__dirname, 'INV_backup_esta_maniana.csv');

const sqlConfig = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

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

// Unidades SQL Server → código corto
const UNIT_MAP = {
  'ONZAS':            'oz',
  'ONZAS FLUIDAS':    'oz fl',
  'UNIDAD':           'u',
  'UNIDADES':         'u',
  'LIBRA':            'lb',
  'LITRO':            'lt',
  'KILOGRAMO':        'kg',
  'KG':               'kg',
  'GRAMO':            'gr',
  'GRAMOS':           'gr',
  'MILILITRO':        'ml',
  'MILILITROS':       'ml',
  'BARRIL':           'barril',
  'BOTELLA 0.75 LT':  'botella',
  'BOTELLA 0.70 LT':  'botella',
  'PORCION':          'porcion',
  'REBANADA':         'rebanada',
  'CAJA':             'caja',
  'PAQUETE':          'paquete',
  'GALON':            'galon',
  'BOLSA 2 Kg':       'bolsa 2kg',
  'BOLSA 1 KG':       'bolsa 1kg',
};
const normalizeUnit = s =>
  !s ? 'u' : (UNIT_MAP[s.trim().toUpperCase()] ?? UNIT_MAP[s.trim()] ?? s.trim().toLowerCase().slice(0, 20));

// Código presentación → unidad (para sub-recetas usadas como ingrediente)
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

function parseCsv(filePath) {
  const lines = fs.readFileSync(filePath, 'utf8').split(/\r?\n/);
  const groups = {};
  for (let i = 1; i < lines.length; i++) {
    const line = lines[i].trim();
    if (!line) continue;
    const cols     = line.split(',');
    const padre    = (cols[0] ?? '').trim();
    const mp       = (cols[1] ?? '').trim();
    const cantMp   = parseFloat((cols[2] ?? '').trim()) || null;
    const activo   = (cols[3] ?? '').trim().toUpperCase();
    const codPres  = (cols[6] ?? '').trim();
    const cantPres = parseFloat((cols[7] ?? '').trim()) || null;
    if (!padre || !mp || activo !== 'SI') continue;
    if (!groups[padre]) groups[padre] = [];
    groups[padre].push({ mpCodigo: mp, cantidadMp: cantMp, codPresentacion: codPres, cantPresent: cantPres });
  }
  return groups;
}

async function main() {
  log('================================================');
  log('RESTORE MODIFICADAS_LOCALMENTE DESDE CSV (con unidades SS)');
  log(DRY_RUN ? '*** DRY RUN ***' : '*** MODO REAL ***');
  log('================================================\n');

  log('[0] Parseando CSV...');
  const csvGroups = parseCsv(CSV_PATH);
  log(`    ${Object.keys(csvGroups).length} padres en el CSV.\n`);

  log('[1] Conectando SQL Server...');
  let sqlPool = null;
  try {
    sqlPool = await sql.connect(sqlConfig);
    log('    SQL Server OK');
  } catch (e) {
    log(`    WARN: no se pudo conectar a SQL Server (${e.message}). Se usará productos.unidad como fallback.`);
  }

  log('[2] Conectando PostgreSQL...');
  const pool = new Pool(pgConfig);
  const pg   = await pool.connect();
  log('    PostgreSQL OK\n');

  const now = new Date().toISOString();

  // ── 1. Sub-recetas con modificado_localmente=true ───────────────────────────
  log('[3] Cargando sub-recetas con modificado_localmente=true...');
  const modificadas = (await pg.query(`
    SELECT id, codigo_origen, nombre,
           (SELECT COUNT(*) FROM receta_ingredientes WHERE receta_id = r.id) AS cnt_ingr
    FROM recetas r
    WHERE tipo_receta = 'sub_receta'
      AND activa = true
      AND modificado_localmente = true
      AND codigo_origen IS NOT NULL AND codigo_origen != ''
    ORDER BY codigo_origen
  `)).rows;

  log(`    ${modificadas.length} sub-recetas modificadas localmente.`);

  const conDatos = modificadas.filter(r => csvGroups[String(r.codigo_origen).trim()]);
  const sinDatos = modificadas.filter(r => !csvGroups[String(r.codigo_origen).trim()]);

  log(`    ${conDatos.length} tienen datos en el CSV → se van a restaurar.`);
  log(`    ${sinDatos.length} NO tienen datos en el CSV → se dejan intactas.`);
  sinDatos.forEach(r => log(`      - ${r.codigo_origen} | ${r.nombre} | ingr actuales: ${r.cnt_ingr}`));

  if (conDatos.length === 0) {
    log('\nNada que restaurar. Saliendo.');
    pg.release(); await pool.end();
    if (sqlPool) await sqlPool.close();
    return;
  }

  // ── 2. Obtener proId de SQL Server para cada sub-receta ──────────────────────
  // Mapa: codigo_origen → proId en SS
  const codigos = conDatos.map(r => String(r.codigo_origen).trim());
  const ssProIdMap = {}; // codigo → proId

  if (sqlPool) {
    log('\n[4] Buscando proIds en SQL Server...');
    for (let i = 0; i < codigos.length; i += 200) {
      const chunk = codigos.slice(i, i + 200);
      const req = sqlPool.request();
      chunk.forEach((c, idx) => req.input(`c${idx}`, sql.VarChar, c));
      const ph = chunk.map((_, idx) => `@c${idx}`).join(',');
      const res = (await req.query(`
        SELECT proId, proCodigo
        FROM olComun.dbo.Productos WITH (NOLOCK)
        WHERE proCodigo IN (${ph})
      `)).recordset;
      res.forEach(r => { ssProIdMap[String(r.proCodigo).trim()] = r.proId; });
    }
    const encontradas = codigos.filter(c => ssProIdMap[c]).length;
    log(`    ${encontradas} de ${codigos.length} encontradas en SQL Server.`);
  }

  // ── 3. Obtener unidades reales por (padre_codigo, ingr_codigo) desde SS ──────
  // Mapa: `${padre_codigo}::${ingr_codigo}` → unidad corta
  const ssUnitMap = {};

  if (sqlPool && Object.keys(ssProIdMap).length > 0) {
    log('[5] Cargando unidades reales desde SQL Server MaterialesXProducto...');
    const proIds = codigos.filter(c => ssProIdMap[c]).map(c => ssProIdMap[c]);

    for (let i = 0; i < proIds.length; i += 200) {
      const chunk = proIds.slice(i, i + 200);
      const req = sqlPool.request();
      chunk.forEach((id, idx) => req.input(`id${idx}`, sql.Int, id));
      const ph = chunk.map((_, idx) => `@id${idx}`).join(',');

      const res = (await req.query(`
        SELECT
          parent.proCodigo  AS sub_codigo,
          ingr.proCodigo    AS ingr_codigo,
          ISNULL(uni.uniNombre, 'UNIDAD') AS unidad_nombre
        FROM olComun.dbo.MaterialesXProducto mx WITH (NOLOCK)
        INNER JOIN olComun.dbo.Productos parent WITH (NOLOCK) ON parent.proId = mx.proId
        INNER JOIN olComun.dbo.Productos ingr   WITH (NOLOCK) ON ingr.proId = mx.proIdMaterial
        LEFT  JOIN olComun.dbo.Unidades  uni    WITH (NOLOCK) ON uni.uniId   = mx.uniId
        WHERE mx.mxprEliminado = 0
          AND mx.proId IN (${ph})
      `)).recordset;

      res.forEach(r => {
        const key = `${String(r.sub_codigo).trim()}::${String(r.ingr_codigo).trim()}`;
        ssUnitMap[key] = normalizeUnit(r.unidad_nombre);
      });
    }
    log(`    ${Object.keys(ssUnitMap).length} pares (sub_receta, ingrediente) con unidad de SS.`);
  }

  // ── 4. Pre-cargar mapas de productos y sub-recetas en PG ─────────────────────
  const allMpCodigos = [...new Set(
    conDatos.flatMap(r => (csvGroups[r.codigo_origen] ?? []).map(x => x.mpCodigo))
  )];

  log(`\n[6] Cargando mapa sub-recetas PG (${allMpCodigos.length} códigos)...`);
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
  log(`    ${Object.keys(recetaMap).length} sub-recetas mapeadas.`);

  log('[7] Cargando mapa productos PG (para unidad fallback)...');
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

  // ── 5. Restaurar ──────────────────────────────────────────────────────────────
  log('\n[8] Restaurando ingredientes...\n');

  let totalDeleted  = 0;
  let totalInserted = 0;
  let totalSkipped  = 0;
  let ssUnitsUsed   = 0;
  let fallbackUsed  = 0;
  const noEncontrados = new Set();

  for (const receta of conDatos) {
    const codigo   = String(receta.codigo_origen).trim();
    const filasCsv = csvGroups[codigo] ?? [];
    const rows     = [];

    for (const fila of filasCsv) {
      const mp = fila.mpCodigo;
      let productoId  = null;
      let subRecetaId = null;
      let cantidad    = null;
      let unidad      = 'u';

      if (fila.codPresentacion && recetaMap[mp]) {
        // Ingrediente es una sub-receta → usar Código Presentación del CSV
        subRecetaId = recetaMap[mp];
        cantidad    = fila.cantPresent ?? 1;
        unidad      = presToUnit(fila.codPresentacion);

      } else if (prodMap[mp]) {
        // Ingrediente crudo → cantidad del CSV, unidad de SQL Server
        productoId = prodMap[mp].id;
        cantidad   = fila.cantidadMp ?? 1;

        const ssKey = `${codigo}::${mp}`;
        if (ssUnitMap[ssKey]) {
          // ✅ Unidad real desde SQL Server MaterialesXProducto
          unidad = ssUnitMap[ssKey];
          ssUnitsUsed++;
        } else {
          // ⚠️ Fallback: unidad base del producto en PG
          unidad = prodMap[mp].unidad ?? 'u';
          if (!unidad || unidad.trim() === '') unidad = 'u';
          fallbackUsed++;
          log(`    FALLBACK unidad: ${codigo} / ${mp} → ${unidad}`);
        }

      } else {
        noEncontrados.add(mp);
        totalSkipped++;
        continue;
      }

      if (!cantidad || cantidad <= 0) cantidad = 1;

      rows.push({
        receta_id:          receta.id,
        producto_id:        productoId,
        sub_receta_id:      subRecetaId,
        cantidad_por_plato: cantidad,
        unidad:             String(unidad).slice(0, 20),
        aud_usuario:        'restore_csv',
        created_at:         now,
        updated_at:         now,
      });
    }

    const anteriorCount = parseInt(receta.cnt_ingr, 10);

    if (!DRY_RUN) {
      await pg.query(`DELETE FROM receta_ingredientes WHERE receta_id = $1`, [receta.id]);
      totalDeleted += anteriorCount;

      if (rows.length > 0) {
        const cols = ['receta_id','producto_id','sub_receta_id','cantidad_por_plato','unidad','aud_usuario','created_at','updated_at'];
        const params = [];
        const rowParts = rows.map(row => {
          const ph = cols.map(col => { params.push(row[col] ?? null); return `$${params.length}`; });
          return `(${ph.join(',')})`;
        });
        await pg.query(
          `INSERT INTO receta_ingredientes (${cols.join(',')}) VALUES ${rowParts.join(',')}`,
          params
        );
      }
    } else {
      totalDeleted += anteriorCount;
    }

    totalInserted += rows.length;
    log(`  [${DRY_RUN ? 'DRY' : 'OK'}] ${codigo} | ${receta.nombre?.slice(0, 35)}`);
    log(`         antes: ${anteriorCount} → ahora: ${rows.length} ingr`);
  }

  if (noEncontrados.size > 0) {
    log(`\n  Códigos MP no encontrados en PG (${noEncontrados.size}):`);
    [...noEncontrados].forEach(c => log(`    - ${c}`));
  }

  log('\n================================================');
  log('RESUMEN:');
  log(`  Sub-recetas restauradas:           ${conDatos.length}`);
  log(`  Ingredientes eliminados:           ${totalDeleted}`);
  log(`  Ingredientes insertados:           ${totalInserted}`);
  log(`  Unidades desde SQL Server:         ${ssUnitsUsed}`);
  log(`  Unidades fallback (productos.unidad): ${fallbackUsed}`);
  log(`  Ingredientes no mapeados:          ${totalSkipped}`);
  log(`  Sub-recetas sin datos CSV:         ${sinDatos.length} (no tocadas)`);
  log(DRY_RUN ? '\nDRY-RUN OK. Ejecuta sin --dry-run para aplicar.' : '\n✓ Restauración completada.');
  log('================================================');

  pg.release();
  await pool.end();
  if (sqlPool) await sqlPool.close();
}

main().catch(err => {
  console.error('\nERROR:', err.message, err.stack);
  process.exit(1);
});
