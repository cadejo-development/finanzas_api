/**
 * Seed inicial de precios en catálogos de ventas externas.
 * Fuente: Listado de precios Cadejo Brewing (Excel vigente).
 * Uso: node database/_seed_catalogos_precio.js [--dry-run]
 */
const { Pool } = require('pg');

const PG = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432, database: 'compras_db', user: 'cadejo_admin',
  password: 'Holamundo#3..', ssl: { rejectUnauthorized: false },
};

const IVA = 0.13;
const sin = (x) => +x;
const con = (x) => +(x * (1 + IVA)).toFixed(4);

// ── Cervezas de línea en formato BOX/CAJA 24 unidades ────────────────────────
// Incluye: Roja, WAPA, Negra, Mera Belga, Hija de Pooh, Suegra, Nacional.
// Skyhopper va por separado (precio $41 propio). WAPA barril va en Barril.
const LINEA_BOX = [
  { codigo: 'PT0205021', comment: 'CERVEZA DE LINEA CAJA 24 UN. (genérico/mix)' },
  { codigo: 'PT0206001', comment: 'CERVEZA ROJA BOX 24 U.' },
  { codigo: 'PT0206002', comment: 'CERVEZA WAPA BOX 24 U.' },
  { codigo: 'PT0206003', comment: 'CERVEZA NEGRA BOX 24 U.' },
  { codigo: 'PT0206004', comment: 'CERVEZA MERA BELGA BOX 24 U.' },
  { codigo: 'PT0206005', comment: 'CERVEZA HIJA DE POOH BOX 24 U.' },
  { codigo: 'PT0206006', comment: 'CERVEZA SUEGRA CAJA 24 U.' },
  { codigo: 'PT0206016', comment: 'CERVEZA LA NACIONAL BOX 24 U.' },
];
const SKYHOPPER = { codigo: 'PT0206012', comment: 'CERVEZA SKYHOPPER BOX 24 U.' };

// ── Mapeo catálogo → productos con precios del Excel ─────────────────────────
// Formato: { catalogoNombre, lineas: [{ codigoProducto, sin_iva }] }
const SEEDS = [
  {
    catalogo: 'Estándar',          // tarifa on-premise general
    lineas: [
      ...LINEA_BOX.map(p => ({ ...p, sin_iva: 36.00 })),            // línea: $36/caja
      { ...SKYHOPPER, sin_iva: 41.00 },                              // skyhopper: $41/caja
      { codigo: 'PT0206015', sin_iva: 41.50, comment: 'CERVEZA CINCUEYUCA BOX 24 U.' },
    ],
  },
  {
    catalogo: 'Skyhopper',         // catálogo exclusivo canal Skyhopper
    lineas: [
      { ...SKYHOPPER, sin_iva: 41.00 },
    ],
  },
  {
    catalogo: 'Temporada Bot',     // precio temporada = $41/caja para todo
    lineas: [
      ...LINEA_BOX.map(p => ({ ...p, sin_iva: 41.00 })),
      { ...SKYHOPPER, sin_iva: 41.00 },
    ],
  },
  {
    catalogo: 'Pedidos Ya',        // $38.40 línea, $41 Skyhopper
    lineas: [
      ...LINEA_BOX.map(p => ({ ...p, sin_iva: 38.40 })),
      { ...SKYHOPPER, sin_iva: 41.00 },
    ],
  },
  {
    catalogo: 'Republik',          // 2 cajas por $45.20 → $22.60 c/u, mínimo 2 cajas
    lineas: [
      ...LINEA_BOX.map(p => ({ ...p, sin_iva: 22.60, cantidad_minima: 2 })),
      { ...SKYHOPPER, sin_iva: 41.00 },
    ],
  },
  {
    catalogo: 'Barril',            // barril línea $90 / temporada $100
    lineas: [
      { codigo: 'PT0101001', sin_iva: 90.00 },   // CERVEZA ROJA BARRIL
      { codigo: 'PT0101002', sin_iva: 90.00 },   // CERVEZA WAPA BARRIL
      { codigo: 'PT0101003', sin_iva: 90.00 },   // CERVEZA NEGRA BARRIL
      { codigo: 'PT0101004', sin_iva: 90.00 },   // CERVEZA MERA BELGA BARRIL
      { codigo: 'PT0101005', sin_iva: 90.00 },   // CERVEZA HIJA DE POOH BARRIL
      { codigo: 'PT0101006', sin_iva: 90.00 },   // CERVEZA SUEGRA BARRIL
      { codigo: 'PT0101019', sin_iva: 90.00 },   // CERVEZA SIGUANATOR BARRIL
      { codigo: 'PT0101024', sin_iva: 90.00 },   // CERVEZA KOLOSCHA BARRIL
      { codigo: 'PT0101030', sin_iva: 90.00 },   // CERVEZA SKYHOPPER BARRIL
    ],
  },
  {
    catalogo: 'Temporada Barril',  // barril temporada $100
    lineas: [
      { codigo: 'PT0101001', sin_iva: 100.00 },
      { codigo: 'PT0101002', sin_iva: 100.00 },
      { codigo: 'PT0101003', sin_iva: 100.00 },
      { codigo: 'PT0101004', sin_iva: 100.00 },
      { codigo: 'PT0101005', sin_iva: 100.00 },
      { codigo: 'PT0101006', sin_iva: 100.00 },
      { codigo: 'PT0101019', sin_iva: 100.00 },
      { codigo: 'PT0101024', sin_iva: 100.00 },
      { codigo: 'PT0101030', sin_iva: 100.00 },
    ],
  },
  {
    catalogo: 'Hotel Quality',     // barril Hotel Quality $80.23
    lineas: [
      { codigo: 'PT0101001', sin_iva: 80.23 },
      { codigo: 'PT0101002', sin_iva: 80.23 },
      { codigo: 'PT0101003', sin_iva: 80.23 },
      { codigo: 'PT0101004', sin_iva: 80.23 },
      { codigo: 'PT0101005', sin_iva: 80.23 },
      { codigo: 'PT0101006', sin_iva: 80.23 },
      { codigo: 'PT0101019', sin_iva: 80.23 },
      { codigo: 'PT0101024', sin_iva: 80.23 },
      { codigo: 'PT0101030', sin_iva: 80.23 },
    ],
  },
];

async function main() {
  const dryRun = process.argv.includes('--dry-run');
  const pg = new Pool(PG);
  await pg.query('SELECT 1');
  console.log('✓ Conectado a compras_db');

  // Cargar catálogos existentes
  const { rows: cats } = await pg.query('SELECT id, nombre FROM ventas_catalogos_precio');
  const catMap = Object.fromEntries(cats.map(c => [c.nombre, c.id]));
  console.log('Catálogos encontrados:', Object.keys(catMap).join(', '));

  let totalOk = 0, totalSkip = 0, totalError = 0;

  for (const seed of SEEDS) {
    const catId = catMap[seed.catalogo];
    if (!catId) { console.warn(`  ⚠ Catálogo "${seed.catalogo}" no encontrado`); continue; }

    console.log(`\n── ${seed.catalogo} (id=${catId}) ──`);

    for (const l of seed.lineas) {
      // Buscar producto por código
      const { rows: prods } = await pg.query(
        'SELECT id, nombre FROM productos WHERE codigo=$1 AND activo=true LIMIT 1',
        [l.codigo]
      );

      if (!prods.length) {
        console.log(`  ✗ ${l.codigo} — no encontrado`);
        totalError++;
        continue;
      }

      const prod = prods[0];
      const sinIva = sin(l.sin_iva);
      const conIva = con(l.sin_iva);

      console.log(`  ${dryRun ? '[DRY]' : ''} ${l.codigo} ${prod.nombre.slice(0,40).padEnd(40)} → $${sinIva} / $${conIva}`);

      if (!dryRun) {
        const cantMin = l.cantidad_minima ?? 1;
        await pg.query(`
          INSERT INTO ventas_catalogo_precio_lineas (catalogo_id, producto_id, precio_sin_iva, precio_con_iva, cantidad_minima, created_at, updated_at)
          VALUES ($1, $2, $3, $4, $5, NOW(), NOW())
          ON CONFLICT (catalogo_id, producto_id) DO UPDATE SET
            precio_sin_iva=$3, precio_con_iva=$4, cantidad_minima=$5, updated_at=NOW()
        `, [catId, prod.id, sinIva, conIva, cantMin]);
      }
      totalOk++;
    }
  }

  // Actualizar updated_at de catálogos
  if (!dryRun) {
    await pg.query(`UPDATE ventas_catalogos_precio SET updated_at=NOW() WHERE nombre = ANY($1)`,
      [SEEDS.map(s => s.catalogo)]);
  }

  console.log(`\n✅ Listo — OK: ${totalOk}, Skip/Error: ${totalError}`);
  if (dryRun) console.log('(modo dry-run, nada fue guardado)');
  await pg.end();
}

main().catch(e => { console.error('❌', e.message); process.exit(1); });
