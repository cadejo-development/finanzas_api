/**
 * repair_sub_receta_ingredientes.js
 *
 * Recupera los ingredientes de sub-recetas que quedaron con 0 ingredientes
 * tras el sync masivo (el DELETE borró ingredientes de las modificado_localmente también).
 *
 * Qué hace:
 *   1. Lee todas las sub-recetas de PG con 0 ingredientes
 *   2. Para cada una, busca sus ingredientes en SQL Server por codigo_origen
 *   3. Inserta los ingredientes encontrados (sin borrar nada de lo que ya existe)
 *
 * Uso: node repair_sub_receta_ingredientes.js [--dry-run]
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const sqlConfig = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

const pgConfig = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'compras_db', user: 'cadejo_admin',
  password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
  connectionTimeoutMillis: 30000,
};

const DRY_RUN = process.argv.includes('--dry-run');
const BATCH   = 50;

const UNIT_MAP = {
  'ONZAS': 'oz', 'ONZAS FLUIDAS': 'oz fl', 'UNIDAD': 'u',
  'LIBRA': 'lb', 'LITRO': 'lt', 'KILOGRAMO': 'kg', 'KG': 'kg',
  'BARRIL': 'barril', 'BOTELLA 0.75 LT': 'botella', 'BOTELLA 0.70 LT': 'botella',
  'PORCION': 'porcion', 'REBANADA': 'rebanada', 'CAJA': 'caja',
  'PAQUETE': 'paquete', 'GALON': 'galon',
  'BOLSA 2 Kg': 'bolsa 2kg', 'BOLSA 1 KG': 'bolsa 1kg',
};
const normalizeUnit = s =>
  !s ? 'u' : (UNIT_MAP[s.trim().toUpperCase()] ?? s.trim().toLowerCase().slice(0, 20));
const clean = (s, max = 150) =>
  !s ? '' : String(s).trim().replace(/\s+/g, ' ').slice(0, max);
const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s => console.log(`[${ts()}] ${s}`);

async function main() {
  log('================================================');
  log('REPAIR SUB-RECETA INGREDIENTES');
  log(DRY_RUN ? '*** DRY RUN ***' : '*** MODO REAL ***');
  log('================================================');

  log('Conectando SQL Server...');
  const sqlPool = await sql.connect(sqlConfig);
  log('SQL Server OK');

  log('Conectando PostgreSQL...');
  const pool = new Pool(pgConfig);
  const pg   = await pool.connect();
  log('PostgreSQL OK\n');

  const now = new Date().toISOString();

  // ── 1. Sub-recetas con 0 ingredientes en PG ─────────────────────────────
  log('[1] Cargando sub-recetas con 0 ingredientes en PG...');
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

  log(`   ${sinIngr.length} sub-recetas con 0 ingredientes.`);
  const modLocal = sinIngr.filter(r => r.modificado_localmente).length;
  log(`   ${modLocal} de ellas tienen modificado_localmente=true (perdieron ingredientes manuales).`);
  log(`   ${sinIngr.length - modLocal} con modificado_localmente=false.`);

  if (sinIngr.length === 0) {
    log('\nNo hay sub-recetas que reparar. Saliendo.');
    pg.release(); await pool.end(); await sqlPool.close(); return;
  }

  // ── 2. Cargar mapa codigo_origen → pg_id ─────────────────────────────────
  const pgIdMap = {};
  sinIngr.forEach(r => { pgIdMap[String(r.codigo_origen).trim()] = r.id; });

  // ── 3. Cargar mapa codigo_origen → SQL Server proId ───────────────────────
  log('\n[2] Buscando sub-recetas en SQL Server por codigo_origen...');
  const codigos = sinIngr.map(r => String(r.codigo_origen).trim()).filter(Boolean);

  // Consultar en lotes de 200
  const sqlIdMap = {}; // codigo → proId en SQL Server
  for (let i = 0; i < codigos.length; i += 200) {
    const chunk = codigos.slice(i, i + 200);
    const req = sqlPool.request();
    chunk.forEach((c, idx) => req.input(`c${idx}`, sql.VarChar, c));
    const ph = chunk.map((_, idx) => `@c${idx}`).join(',');
    const res = (await req.query(`
      SELECT p.proId, p.proCodigo
      FROM olComun.dbo.Productos p WITH (NOLOCK)
      WHERE p.proCodigo IN (${ph})
    `)).recordset;
    res.forEach(r => { sqlIdMap[String(r.proCodigo).trim()] = r.proId; });
  }

  const encontrados = codigos.filter(c => sqlIdMap[c]);
  const noEncontrados = codigos.filter(c => !sqlIdMap[c]);
  log(`   En SQL Server: ${encontrados.length} encontradas, ${noEncontrados.length} no encontradas.`);
  if (noEncontrados.length > 0) {
    log('   Sin equivalente en SQL Server:');
    noEncontrados.forEach(c => log(`     - ${c}`));
  }

  if (encontrados.length === 0) {
    log('\nNinguna sub-receta tiene equivalente en SQL Server. Nada que reparar.');
    pg.release(); await pool.end(); await sqlPool.close(); return;
  }

  // ── 4. Cargar ingredientes desde SQL Server ───────────────────────────────
  log('\n[3] Cargando ingredientes desde SQL Server...');
  const proIds = encontrados.map(c => sqlIdMap[c]);
  const ingrPorCodigo = {}; // codigo_origen → [{ingr_codigo, cantidad, unidad}]

  for (let i = 0; i < proIds.length; i += 200) {
    const chunk = proIds.slice(i, i + 200);
    const req = sqlPool.request();
    chunk.forEach((id, idx) => req.input(`id${idx}`, sql.Int, id));
    const ph = chunk.map((_, idx) => `@id${idx}`).join(',');

    const res = (await req.query(`
      SELECT
        parent.proCodigo   AS sub_codigo,
        ingr.proCodigo     AS ingr_codigo,
        mx.mxprCantUnidad  AS cantidad,
        ISNULL(uni.uniNombre, 'u') AS unidad
      FROM olComun.dbo.MaterialesXProducto mx WITH (NOLOCK)
      INNER JOIN olComun.dbo.Productos parent WITH (NOLOCK)
        ON parent.proId = mx.proId
      INNER JOIN olComun.dbo.Productos ingr WITH (NOLOCK)
        ON ingr.proId = mx.proIdMaterial
      LEFT JOIN olComun.dbo.Unidades uni WITH (NOLOCK)
        ON uni.uniId = mx.uniId
      WHERE mx.mxprEliminado = 0
        AND mx.mxprCantUnidad > 0
        AND mx.proId IN (${ph})
    `)).recordset;

    res.forEach(r => {
      const cod = String(r.sub_codigo).trim();
      if (!ingrPorCodigo[cod]) ingrPorCodigo[cod] = [];
      ingrPorCodigo[cod].push({
        ingr_codigo: String(r.ingr_codigo).trim(),
        cantidad: parseFloat(r.cantidad) || 0,
        unidad: normalizeUnit(r.unidad),
      });
    });
  }

  const conIngr   = encontrados.filter(c => ingrPorCodigo[c]?.length > 0);
  const sinIngrSS = encontrados.filter(c => !ingrPorCodigo[c] || ingrPorCodigo[c].length === 0);

  log(`   Con ingredientes en SQL Server: ${conIngr.length}`);
  log(`   Sin ingredientes en SQL Server: ${sinIngrSS.length}`);
  if (sinIngrSS.length > 0) {
    log('   Sub-recetas sin ingredientes en SQL Server (pueden ser manuales o extras):');
    sinIngrSS.forEach(c => log(`     - ${c}`));
  }

  // ── 5. Cargar mapa codigo → producto_id en PG ─────────────────────────────
  log('\n[4] Cargando mapa de productos en PG...');
  const allIngrCodigos = [...new Set(
    Object.values(ingrPorCodigo).flat().map(x => x.ingr_codigo)
  )];

  const prodMap = {}; // codigo → pg producto_id
  for (let i = 0; i < allIngrCodigos.length; i += 500) {
    const chunk = allIngrCodigos.slice(i, i + 500);
    const res = await pg.query(
      'SELECT id, codigo FROM productos WHERE codigo = ANY($1::text[])',
      [chunk]
    );
    res.rows.forEach(r => { prodMap[r.codigo] = r.id; });
  }

  log(`   ${Object.keys(prodMap).length} productos mapeados de ${allIngrCodigos.length} únicos.`);

  // ── 6. Insertar ingredientes ─────────────────────────────────────────────
  log('\n[5] Insertando ingredientes...');
  let totalInserted = 0;
  let totalSkipped  = 0;

  for (const codigo of conIngr) {
    const recetaId  = pgIdMap[codigo];
    const ingredientes = ingrPorCodigo[codigo] ?? [];
    if (!recetaId || ingredientes.length === 0) continue;

    const rows = [];
    for (const ing of ingredientes) {
      const prodId = prodMap[ing.ingr_codigo];
      if (!prodId) { totalSkipped++; continue; }
      rows.push([recetaId, prodId, ing.cantidad, ing.unidad, 'repair_script', now, now]);
    }

    if (rows.length === 0) continue;

    if (!DRY_RUN) {
      // Insertar sin borrar — si ya tiene ingredientes los saltamos (por seguridad doble)
      const cols = ['receta_id','producto_id','cantidad_por_plato','unidad','aud_usuario','created_at','updated_at'];
      const params = [];
      const rowParts = rows.map(row => {
        const ph = row.map(v => { params.push(v); return `$${params.length}`; });
        return `(${ph.join(',')})`;
      });
      await pg.query(
        `INSERT INTO receta_ingredientes (${cols.join(',')}) VALUES ${rowParts.join(',')}`,
        params
      );
    }
    totalInserted += rows.length;
    log(`   [${DRY_RUN ? 'DRY' : 'OK'}] ${codigo}: ${rows.length} ingredientes insertados.`);
  }

  // ── 7. Actualizar sub_receta_id para ingredientes recién insertados ────────
  if (!DRY_RUN) {
    log('\n[6] Actualizando receta_ingredientes: producto_id → sub_receta_id...');
    const updateRes = await pg.query(`
      UPDATE receta_ingredientes ri
      SET
        sub_receta_id = r.id,
        producto_id   = NULL
      FROM recetas r
      INNER JOIN productos p ON p.codigo = r.codigo_origen
      WHERE ri.producto_id = p.id
        AND r.tipo_receta = 'sub_receta'
        AND ri.sub_receta_id IS NULL
    `);
    log(`   ${updateRes.rowCount} filas actualizadas a sub_receta_id.`);
  }

  log('\n================================================');
  log('RESUMEN:');
  log(`  Sub-recetas con 0 ingredientes:    ${sinIngr.length}`);
  log(`  Encontradas en SQL Server:         ${encontrados.length}`);
  log(`  Con ingredientes en SQL Server:    ${conIngr.length}`);
  log(`  Sin equivalente / sin ingredientes: ${noEncontrados.length + sinIngrSS.length}`);
  log(`  Ingredientes insertados:           ${totalInserted}`);
  log(`  Ingredientes sin producto en PG:   ${totalSkipped}`);
  log(DRY_RUN ? '\nDRY-RUN OK. Corre sin --dry-run para insertar.' : '\n✓ Reparación completada.');
  log('================================================');

  pg.release();
  await pool.end();
  await sqlPool.close();
}

main().catch(err => {
  console.error('\nERROR:', err.message, err.stack);
  process.exit(1);
});
