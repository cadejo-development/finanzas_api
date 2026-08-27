/**
 * sync_merma_ventas_brilo.js
 *
 * Sincroniza ventas de cerveza draft desde Brilo (olRestaurante, SQL Server)
 * → merma_ventas_brilo (gestion_operaciones_db, PostgreSQL).
 *
 * - Lee detalle de tickets por fecha y sucursal filtrando categorías de cerveza.
 * - Mapea producto Brilo → cerveza_id y presentacion_id usando tablas locales.
 * - Calcula oz_efectivas usando merma_presentaciones.oz_efectivas.
 * - Upsert idempotente por (fecha, suc_id_brilo, presentacion_brilo, cerveza_brilo).
 * - Vincula al merma_inventario del día si existe.
 *
 * Uso:
 *   node database/sync_merma_ventas_brilo.js              → hoy, todas las sucursales
 *   node database/sync_merma_ventas_brilo.js --dry-run    → simula sin escribir
 *   node database/sync_merma_ventas_brilo.js --fecha=2026-08-25
 *   node database/sync_merma_ventas_brilo.js --suc=11
 *   node database/sync_merma_ventas_brilo.js --listar     → muestra productos Brilo sin mapear
 */

require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const sql      = require('mssql');
const { Pool } = require('pg');

// ── Conexión Brilo (olRestaurante) ────────────────────────────────────────────
const BRILO_CFG = {
  user:     process.env.DB_USERNAME_ORIGEN,
  password: process.env.DB_PASSWORD_ORIGEN,
  server:   process.env.DB_HOST_ORIGEN,
  port:     Number(process.env.DB_PORT_ORIGEN) || 2033,
  database: 'olRestaurante',
  options:  { trustServerCertificate: true, encrypt: false, connectTimeout: 30000, requestTimeout: 300000 },
};

// ── Conexión PostgreSQL (gestion_operaciones_db) ───────────────────────────────
const PG_CFG = {
  host:     process.env.DB_HOST_COMPRAS,
  port:     Number(process.env.DB_PORT_COMPRAS) || 5432,
  database: process.env.DB_DATABASE_COMPRAS,
  user:     process.env.DB_USERNAME_COMPRAS,
  password: process.env.DB_PASSWORD_COMPRAS,
  ssl: { rejectUnauthorized: false },
};

// ── Mapa: Brilo sucId → nuestro sucursal_id en gestion_operaciones_db ─────────
// Confirmar con la tabla sucursales de la DB antes de usar.
// El sucursal_id de gestion_operaciones_db es el mismo que se registra
// en merma_inventarios.sucursal_id al abrir el registro del día.
const SUC_MAP = {
   1: { sucursalId:  1, nombre: 'FAB - ZONA ROSA'      },
   3: { sucursalId:  2, nombre: 'RES - ZONA ROSA'       },
   6: { sucursalId:  3, nombre: 'RES - LA LIBERTAD'     },
   7: { sucursalId:  4, nombre: 'RES - AEROPUERTO-1'    },
   8: { sucursalId:  5, nombre: 'RES - AEROPUERTO-2'    },
  10: { sucursalId:  7, nombre: 'RES - PASEO VENECIA'   },
  11: { sucursalId:  8, nombre: 'RES - SANTA ELENA'     },
  12: { sucursalId:  9, nombre: 'RES - HUIZUCAR'        },
  13: { sucursalId: 10, nombre: 'RES - OPICO'           },
  16: { sucursalId: 16, nombre: 'RES - MALCRIADAS AE2'  },
  19: { sucursalId: 11, nombre: 'RES - CASA GUIROLA'    },
};

// Categorías Brilo que son cervezas draft/barril
const CATS_CERVEZA = [
  'Cerveza Draft',
  'Cerveza Botella',
  'Cerveza Growler',
  'Bebidas con Alcohol',
  'Cervezas VR Malcriadas AE2',
  'Bebidas Malcriadas AE2 s/a',
];

const ts  = () => new Date().toTimeString().slice(0, 8);
const log = (s) => console.log(`[${ts()}] ${s}`);

// ── Hora El Salvador (UTC-6) ───────────────────────────────────────────────────
function fechaSV() {
  const now = new Date();
  return new Date(now.getTime() - 6 * 3600 * 1000).toISOString().slice(0, 10);
}

// ── Leer ventas de Brilo para una fecha y lista de sucIds ─────────────────────
async function getVentasBrilo(pool, fecha, sucIds) {
  const sucFilter = sucIds.map((id) => `MCT.sucIdOrigenSync = ${id}`).join(' OR ');

  const r = await pool.request().query(`
    SELECT
      MCT.sucIdOrigenSync                                                         AS suc_id_brilo,
      CAST(
        MCT.mctrstFecHoraCerrada
        AT TIME ZONE 'UTC'
        AT TIME ZONE 'Central America Standard Time'
      AS DATE)                                                                    AS fecha,
      LTRIM(RTRIM(PRO.proCodigo))                                                 AS producto_codigo,
      LTRIM(RTRIM(PRO.proNombre))                                                 AS producto_nombre,
      LTRIM(RTRIM(CPR.cprNombre))                                                 AS categoria_brilo,
      SUM(DET.dctrstCantidad)                                                     AS cantidad
    FROM olRestaurante.dbo.maeCuentasRst   MCT WITH (NOLOCK)
    INNER JOIN olRestaurante.dbo.detCuentasRst  DET WITH (NOLOCK) ON DET.mctrstId = MCT.mctrstId
    INNER JOIN olComun.dbo.Productos            PRO WITH (NOLOCK) ON PRO.proId    = DET.proId
    LEFT  JOIN olComun.dbo.CategoriasProductos  CPR WITH (NOLOCK) ON CPR.cprId   = PRO.cprId
    WHERE MCT.mctrstEliminado    = 0
      AND DET.dctrstEliminado    = 0
      AND DET.dctrstIdModificadorDe IS NULL
      AND (${sucFilter})
      AND CAST(
        MCT.mctrstFecHoraCerrada
        AT TIME ZONE 'UTC'
        AT TIME ZONE 'Central America Standard Time'
      AS DATE) = '${fecha}'
    GROUP BY
      MCT.sucIdOrigenSync,
      CAST(
        MCT.mctrstFecHoraCerrada
        AT TIME ZONE 'UTC'
        AT TIME ZONE 'Central America Standard Time'
      AS DATE),
      PRO.proCodigo,
      PRO.proNombre,
      CPR.cprNombre
    ORDER BY MCT.sucIdOrigenSync, SUM(DET.dctrstCantidad) DESC
  `);

  return r.recordset;
}

// ── Cargar catálogos locales desde PostgreSQL ─────────────────────────────────
async function cargarCatalogos(pg) {
  const { rows: cervezas } = await pg.query(
    `SELECT id, LOWER(nombre) AS nombre FROM merma_cervezas ORDER BY orden`
  );
  const { rows: pres } = await pg.query(
    `SELECT id, LOWER(presentacion) AS presentacion, oz_efectivas FROM merma_presentaciones ORDER BY orden`
  );
  return { cervezas, pres };
}

// ── Intentar mapear producto Brilo → cerveza_id + presentacion_id + oz ────────
function mapearProducto(productoNombre, categoria, cervezas, pres) {
  const nombre = (productoNombre || '').toLowerCase();

  // 1. Detectar cerveza por nombre
  let cervezaId   = null;
  let cervezaNom  = null;
  for (const c of cervezas) {
    if (nombre.includes(c.nombre)) {
      cervezaId  = c.id;
      cervezaNom = c.nombre;
      break;
    }
  }

  // 2. Detectar presentación por oz en el nombre (ej "12 oz", "16oz", "23 OZ", "1 lt", "sampler")
  let presId    = null;
  let ozEfect   = null;

  // Buscar número + unidad en el nombre del producto
  const matchOz = nombre.match(/(\d+\.?\d*)\s*(oz|onz)/i);
  const matchL  = nombre.match(/(\d+\.?\d*)\s*(lt|lts|l\b|litro)/i);
  const matchMl = nombre.match(/(\d+)\s*ml/i);
  const isSampler = /sampler/i.test(nombre);
  const isGrowler = /growler/i.test(nombre) || /32/.test(nombre);
  const isRefill  = /refill|recarga/i.test(nombre);

  let ozBuscada = null;
  if (matchOz)      ozBuscada = parseFloat(matchOz[1]);
  else if (matchL)  ozBuscada = parseFloat(matchL[1]) * 33.814;
  else if (matchMl) ozBuscada = parseFloat(matchMl[1]) / 29.5735;
  else if (isGrowler) ozBuscada = 32;

  if (ozBuscada !== null) {
    // Buscar la presentación más cercana (±2 oz de tolerancia)
    let mejorDist = Infinity;
    for (const p of pres) {
      const nomP = p.presentacion;
      if (isRefill && !nomP.includes('refill')) continue;
      if (!isRefill && nomP.includes('refill')) continue;
      if (isSampler && !nomP.includes('sampler')) continue;
      if (!isSampler && nomP.includes('sampler')) continue;

      // oz_nominal aproximado (buscar número en presentacion name)
      const mP = nomP.match(/(\d+\.?\d*)\s*(oz|lt|lts)/i);
      if (!mP) continue;
      let ozP = parseFloat(mP[1]);
      if (/lt/i.test(mP[2])) ozP *= 33.814;

      const dist = Math.abs(ozP - ozBuscada);
      if (dist < mejorDist && dist <= 2) {
        mejorDist = dist;
        presId    = p.id;
        ozEfect   = parseFloat(p.oz_efectivas);
      }
    }
  } else if (isSampler) {
    const sp = pres.find((p) => p.presentacion.includes('sampler'));
    if (sp) { presId = sp.id; ozEfect = parseFloat(sp.oz_efectivas); }
  }

  // Si no detectamos oz, usar la categoría para estimar (draft típico = 16 oz)
  if (ozEfect === null && CATS_CERVEZA.includes(categoria)) {
    const p16 = pres.find((p) => p.presentacion.includes('16'));
    if (p16) { presId = p16.id; ozEfect = parseFloat(p16.oz_efectivas); }
  }

  return { cervezaId, presId, ozEfect: ozEfect ?? 0 };
}

// ── Upsert de un lote de ventas ───────────────────────────────────────────────
async function upsertVentas(pg, filas, isDryRun) {
  let ok = 0;
  for (const f of filas) {
    if (isDryRun) { ok++; continue; }
    await pg.query(`
      INSERT INTO merma_ventas_brilo
        (inventario_id, cerveza_id, presentacion_id, presentacion_brilo, cerveza_brilo,
         cantidad, oz_efectivas, fecha, suc_id_brilo, synced_at)
      VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,NOW())
      ON CONFLICT (fecha, suc_id_brilo, presentacion_brilo, cerveza_brilo)
      DO UPDATE SET
        inventario_id  = EXCLUDED.inventario_id,
        cerveza_id     = EXCLUDED.cerveza_id,
        presentacion_id= EXCLUDED.presentacion_id,
        cantidad       = EXCLUDED.cantidad,
        oz_efectivas   = EXCLUDED.oz_efectivas,
        synced_at      = NOW()
    `, [f.inventario_id, f.cerveza_id, f.presId, f.presentacion_brilo,
        f.cerveza_brilo, f.cantidad, f.oz_efectivas, f.fecha, f.suc_id_brilo]);
    ok++;
  }
  return ok;
}

// ── Main ──────────────────────────────────────────────────────────────────────
(async () => {
  const isDryRun = process.argv.includes('--dry-run');
  const isListar = process.argv.includes('--listar');
  const fechaArg = (process.argv.find((a) => a.startsWith('--fecha=')) ?? '').split('=')[1];
  const sucArg   = (process.argv.find((a) => a.startsWith('--suc='))   ?? '').split('=')[1];

  const fecha   = fechaArg || fechaSV();
  const sucIds  = sucArg
    ? [parseInt(sucArg, 10)]
    : Object.keys(SUC_MAP).map(Number);

  console.log(`\n${'█'.repeat(60)}`);
  console.log(`  SYNC MERMA VENTAS BRILO${isDryRun ? ' [DRY RUN]' : ''}`);
  console.log(`  Fecha    : ${fecha}`);
  console.log(`  Sucursales: ${sucIds.join(', ')}`);
  console.log(`${'█'.repeat(60)}\n`);

  const pg = new Pool(PG_CFG);
  await pg.query('SELECT 1');
  log('Conectado a gestion_operaciones_db ✓');

  const { cervezas, pres } = await cargarCatalogos(pg);
  log(`Catálogos: ${cervezas.length} cervezas, ${pres.length} presentaciones`);

  // Inventarios del día por sucursal_id
  const { rows: invRows } = await pg.query(`
    SELECT id, sucursal_id FROM merma_inventarios WHERE fecha = $1
  `, [fecha]);
  const invMap = {};
  invRows.forEach((r) => { invMap[r.sucursal_id] = r.id; });

  log(`Conectando a Brilo (olRestaurante)...`);
  const pool = await sql.connect(BRILO_CFG);
  log('Brilo conectado ✓');

  const brilo = await getVentasBrilo(pool, fecha, sucIds);
  await sql.close();
  log(`Brilo: ${brilo.length} filas de ventas cerveza (todas categorías)`);

  // Filtrar solo categorías de cerveza
  const cervePerRow = brilo.filter((r) => CATS_CERVEZA.includes(r.categoria_brilo));
  log(`Filtradas por categoría cerveza: ${cervePerRow.length} filas`);

  if (isListar) {
    console.log('\n=== PRODUCTOS BRILO (para revisar mapeo) ===');
    const vistos = new Set();
    for (const r of cervePerRow) {
      const key = `${r.suc_id_brilo}|${r.producto_nombre}|${r.categoria_brilo}`;
      if (vistos.has(key)) continue;
      vistos.add(key);
      const { cervezaId, presId, ozEfect } = mapearProducto(r.producto_nombre, r.categoria_brilo, cervezas, pres);
      const estado = cervezaId && presId ? '✓' : `? (cerv=${cervezaId ?? 'NO'}, pres=${presId ?? 'NO'})`;
      console.log(`  suc=${r.suc_id_brilo} | ${r.categoria_brilo.padEnd(28)} | ${r.producto_nombre.padEnd(40)} | ${estado} | ${ozEfect} oz`);
    }
    await pg.end();
    return;
  }

  // Agrupar por suc_id + producto para upsert
  const filas = [];
  let sinMapeo = 0;

  for (const r of cervePerRow) {
    const sucInfo = SUC_MAP[r.suc_id_brilo];
    if (!sucInfo) {
      log(`  ⚠ suc_id_brilo=${r.suc_id_brilo} sin mapeo — omitido`);
      continue;
    }

    const { cervezaId, presId, ozEfect } = mapearProducto(r.producto_nombre, r.categoria_brilo, cervezas, pres);

    if (ozEfect === 0) {
      sinMapeo++;
      log(`  ⚠ Sin oz para "${r.producto_nombre}" (${r.categoria_brilo}) — se registra con oz=0`);
    }

    filas.push({
      inventario_id:      invMap[sucInfo.sucursalId] ?? null,
      cerveza_id:         cervezaId,
      presId:             presId,
      presentacion_brilo: (r.producto_nombre ?? '').slice(0, 80),
      cerveza_brilo:      (r.producto_nombre ?? '').slice(0, 150),
      cantidad:           Number(r.cantidad) || 0,
      oz_efectivas:       (Number(r.cantidad) || 0) * ozEfect,
      fecha:              r.fecha instanceof Date
                            ? r.fecha.toISOString().slice(0, 10)
                            : String(r.fecha).slice(0, 10),
      suc_id_brilo:       r.suc_id_brilo,
    });
  }

  log(`Filas a upsertear: ${filas.length} (sin mapeo oz: ${sinMapeo})`);

  const ok = await upsertVentas(pg, filas, isDryRun);

  console.log(`\n${'═'.repeat(60)}`);
  console.log(`  Upsertados : ${ok}`);
  console.log(`  Sin oz_map : ${sinMapeo}`);
  console.log(`  Dry run    : ${isDryRun}`);
  console.log(`  Finalizado : ${new Date().toISOString()}`);
  console.log(`${'═'.repeat(60)}\n`);

  await pg.end();
})().catch((e) => {
  console.error('FATAL:', e.message);
  process.exit(1);
});
