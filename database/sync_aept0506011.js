require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

/**
 * sync_aept0506011.js
 * Sincroniza los ingredientes de AEPT0506011 desde SQL Server (BRILO) a RDS.
 * Lógica: mxprCantUnidad → cantidad_por_plato, normalizeUnit(uniNombre) → unidad
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const MSSQL_CFG = {
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_PASSWORD_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

const PG_CFG = {
  host: process.env.DB_HOST, port: 5432,
  database: 'compras_db', user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
};

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

const PRO_ID    = 30287;   // proId de AEPT0506011
const RECETA_ID = 42820;   // id en recetas
const CODIGO    = 'AEPT0506011';

async function main() {
  // ── 1. Leer ingredientes desde SQL Server ──────────────────────────────────
  console.log('Leyendo ingredientes de SQL Server...');
  const pool = await sql.connect(MSSQL_CFG);

  const ingRes = await pool.request().query(`
    SELECT
      m.proIdMaterial,
      m.mxprCantUnidad AS cantidad,
      u.uniNombre      AS uni_nombre,
      p.proCodigo      AS codigo_material,
      p.proNombre      AS nombre_material
    FROM olComun.dbo.MaterialesXProducto m
    JOIN olComun.dbo.Productos p ON p.proId = m.proIdMaterial
    LEFT JOIN olComun.dbo.Unidades u ON u.uniId = m.uniId
    WHERE m.proId = ${PRO_ID}
      AND m.mxprEliminado = 0
      AND m.mxprCantUnidad > 0
  `);

  const ingBrilo = ingRes.recordset;
  console.log(`Ingredientes en BRILO: ${ingBrilo.length}`);
  ingBrilo.forEach(r =>
    console.log(`  ${r.codigo_material} | ${r.nombre_material} | cantidad=${r.cantidad} ${r.uni_nombre}`)
  );
  await pool.close();

  // ── 2. Leer estado actual en RDS ───────────────────────────────────────────
  const pg = new Pool(PG_CFG);

  const pgIng = await pg.query(
    `SELECT ri.id, ri.producto_id, ri.cantidad_por_plato, ri.unidad,
            p.codigo_origen AS codigo_material
     FROM receta_ingredientes ri
     JOIN productos p ON p.id = ri.producto_id
     WHERE ri.receta_id = $1`,
    [RECETA_ID]
  );

  console.log(`\nIngredientes en RDS: ${pgIng.rows.length}`);
  pgIng.rows.forEach(r =>
    console.log(`  id=${r.id} | ${r.codigo_material} | cantidad=${r.cantidad_por_plato} ${r.unidad}`)
  );

  // ── 3. Resolver producto_id para cada ingrediente BRILO ───────────────────
  const codigosMaterial = ingBrilo.map(r => r.codigo_material);
  const prodRes = await pg.query(
    `SELECT id, codigo_origen FROM productos WHERE codigo_origen = ANY($1)`,
    [codigosMaterial]
  );
  const productoIdMap = {};
  prodRes.rows.forEach(r => { productoIdMap[r.codigo_origen] = r.id; });

  // ── 4. Sincronizar ─────────────────────────────────────────────────────────
  const now = new Date();
  let actualizados = 0, insertados = 0, sinProducto = 0;

  for (const ing of ingBrilo) {
    const productoId = productoIdMap[ing.codigo_material];
    if (!productoId) {
      console.log(`  ⚠ Sin producto en RDS para ${ing.codigo_material}`);
      sinProducto++;
      continue;
    }

    const cantidad  = parseFloat(ing.cantidad) || 0;
    const unidad    = normalizeUnit(ing.uni_nombre);
    const existente = pgIng.rows.find(r => r.codigo_material === ing.codigo_material);

    if (existente) {
      // Actualizar si cambió
      const cambios = [];
      if (parseFloat(existente.cantidad_por_plato) !== cantidad) cambios.push(`cantidad: ${existente.cantidad_por_plato} → ${cantidad}`);
      if (existente.unidad !== unidad) cambios.push(`unidad: ${existente.unidad} → ${unidad}`);

      if (cambios.length) {
        console.log(`\n  ACTUALIZAR id=${existente.id} (${ing.codigo_material}): ${cambios.join(', ')}`);
        await pg.query(
          `UPDATE receta_ingredientes
           SET cantidad_por_plato=$1, unidad=$2, updated_at=$3
           WHERE id=$4`,
          [cantidad, unidad, now, existente.id]
        );
        actualizados++;
      } else {
        console.log(`  ✓ Sin cambios: ${ing.codigo_material} (${cantidad} ${unidad})`);
      }
    } else {
      // Insertar nuevo
      console.log(`\n  INSERTAR: ${ing.codigo_material} → producto_id=${productoId}, cantidad=${cantidad} ${unidad}`);
      await pg.query(
        `INSERT INTO receta_ingredientes (receta_id, producto_id, cantidad_por_plato, unidad, aud_usuario, created_at, updated_at)
         VALUES ($1, $2, $3, $4, 'sync', $5, $5)`,
        [RECETA_ID, productoId, cantidad, unidad, now]
      );
      insertados++;
    }
  }

  // Ingredientes en RDS que ya no están en BRILO → desactivar
  const codigosEnBrilo = new Set(ingBrilo.map(r => r.codigo_material));
  const huerfanos = pgIng.rows.filter(r => !codigosEnBrilo.has(r.codigo_material));
  if (huerfanos.length) {
    console.log(`\n  ⚠ Ingredientes en RDS que ya NO están en BRILO:`);
    huerfanos.forEach(r => console.log(`    id=${r.id} | ${r.codigo_material} | ${r.cantidad_por_plato} ${r.unidad}`));
    console.log('  (No se eliminaron — verificar manualmente)');
  }

  console.log(`\n✓ Sync completo: ${actualizados} actualizados, ${insertados} insertados, ${sinProducto} sin producto`);

  // Verificar estado final
  const final = await pg.query(
    `SELECT ri.id, ri.cantidad_por_plato, ri.unidad, p.codigo_origen, p.nombre, ri.updated_at
     FROM receta_ingredientes ri
     JOIN productos p ON p.id = ri.producto_id
     WHERE ri.receta_id = $1`,
    [RECETA_ID]
  );
  console.log('\n=== Estado final de ingredientes AEPT0506011 ===');
  console.log(JSON.stringify(final.rows, null, 2));

  await pg.end();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
