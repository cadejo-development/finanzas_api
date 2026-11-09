/**
 * Sync ventas de cerveza Draft desde Brilo hacia merma_ventas_brilo (todas las sucursales).
 *
 * Uso:
 *   node database/sync_merma_ventas_all.js            # solo ayer (1 día)
 *   node database/sync_merma_ventas_all.js --dias 7   # últimos 7 días
 *   node database/sync_merma_ventas_all.js --fecha 2026-11-05  # fecha exacta
 *
 * Las ventas se vinculan al inventario_id del día si existe (cualquier estado).
 * Si no hay inventario registrado, igual se guardan por fecha/sucursal para cuando se cree.
 */

require('dotenv').config();
const sql  = require('mssql');
const { Client } = require('pg');

// ── Parámetros ───────────────────────────────────────────────────────────────
const args  = process.argv.slice(2);
let diasAtras = 1;
let fechaFija = null;

for (let i = 0; i < args.length; i++) {
  if (args[i] === '--dias'  && args[i + 1]) diasAtras = parseInt(args[i + 1]);
  if (args[i] === '--fecha' && args[i + 1]) fechaFija = args[i + 1];
}

// Rango de fechas a procesar (ayer hacia atrás N días)
function rangoFechas() {
  if (fechaFija) return [fechaFija];
  const fechas = [];
  for (let d = 0; d < diasAtras; d++) {
    const dt = new Date();
    dt.setDate(dt.getDate() - 1 - d); // ayer = d=0
    fechas.push(dt.toISOString().slice(0, 10));
  }
  return fechas;
}

// Brilo sucIdOrigenSync → pg sucursal_id
const SUCURSAL_MAP = {
   3: { pg_id:  1, nombre: 'ZONA ROSA'     },
   6: { pg_id:  3, nombre: 'LA LIBERTAD'   },
   7: { pg_id:  4, nombre: 'AEROPUERTO 1'  },
   8: { pg_id:  5, nombre: 'AEROPUERTO 2'  },
  10: { pg_id:  7, nombre: 'PASEO VENECIA' },
  11: { pg_id:  8, nombre: 'SANTA ELENA'   },
  12: { pg_id:  9, nombre: 'HUIZUCAR'      },
  13: { pg_id: 10, nombre: 'OPICO'         },
  19: { pg_id: 11, nombre: 'CASA GUIROLA'  },
};

// ── Conexiones ───────────────────────────────────────────────────────────────
const cfgMssql = {
  user:     process.env.DB_USERNAME_ORIGEN ?? 'olimporeader',
  password: process.env.DB_PASSWORD_ORIGEN ?? 'olimporeader',
  server:   process.env.DB_HOST_ORIGEN     ?? '10.0.4.20',
  port:     parseInt(process.env.DB_PORT_ORIGEN ?? '2033'),
  database: 'olRestaurante',
  options:  { trustServerCertificate: true, encrypt: false, connectTimeout: 30000, requestTimeout: 60000 },
};

const cfgPg = {
  host:     process.env.DB_HOST_COMPRAS ?? process.env.DB_HOST,
  port:     5432,
  database: 'gestion_operaciones_db',
  user:     process.env.DB_USERNAME_COMPRAS ?? process.env.DB_USERNAME ?? 'cadejo_admin',
  password: process.env.DB_PASSWORD_COMPRAS ?? process.env.DB_PASSWORD ?? process.env.PGPASSWORD,
  ssl:      { rejectUnauthorized: false },
};

// ── Query ventas Brilo para una sucursal y fecha ──────────────────────────────
async function fetchVentasBrilo(pool, briloId, fechaBrilo) {
  const result = await pool.request().query(`
    SELECT
      LTRIM(RTRIM(PRO.proNombre))    AS producto_nombre,
      LTRIM(RTRIM(CPR.cprNombre))    AS categoria,
      SUM(DET.dctrstCantidad)        AS cantidad,
      AVG(DET.dctrstPrecio)          AS precio_unitario,
      SUM(DET.dctrstCantidad * DET.dctrstPrecio) AS total
    FROM olRestaurante.dbo.maeCuentasRst MCT WITH (NOLOCK)
    INNER JOIN olRestaurante.dbo.detCuentasRst  DET WITH (NOLOCK) ON DET.mctrstId = MCT.mctrstId
    INNER JOIN olComun.dbo.Productos            PRO WITH (NOLOCK) ON PRO.proId    = DET.proId
    LEFT  JOIN olComun.dbo.CategoriasProductos  CPR WITH (NOLOCK) ON CPR.cprId   = PRO.cprId
    WHERE MCT.mctrstEliminado = 0
      AND DET.dctrstEliminado = 0
      AND DET.dctrstIdModificadorDe IS NULL
      AND MCT.sucIdOrigenSync = ${briloId}
      AND CPR.cprNombre = 'Cerveza Draft'
      AND CAST(MCT.mctrstFecHoraCerrada AT TIME ZONE 'UTC'
               AT TIME ZONE 'Central America Standard Time' AS DATE) = '${fechaBrilo}'
    GROUP BY PRO.proNombre, CPR.cprNombre
    ORDER BY SUM(DET.dctrstCantidad * DET.dctrstPrecio) DESC
  `);
  return result.recordset;
}

// ── Parsear nombre del producto → cerveza + presentación ─────────────────────
function parsearProducto(nombreBrilo, cervezas, presentaciones) {
  const nombreNorm = nombreBrilo.replace(/(\d)(onz|oz)/gi, '$1 $2').replace(/onz/gi, 'oz');
  const cerveza = cervezas.find(c => nombreNorm.toLowerCase().includes(c.nombre.toLowerCase()));

  const matchOz = nombreNorm.match(/(\d+(?:\.\d+)?)\s*oz/i);
  const matchLt  = nombreNorm.match(/(\d+(?:\.\d+)?)\s*l(?:t|ts?)?/i);
  let presLabel = null;
  if (matchOz) presLabel = `${matchOz[1]} Oz`;
  else if (matchLt) presLabel = parseFloat(matchLt[1]) === 1 ? '1 Lt' : `${parseFloat(matchLt[1])} Lts`;

  const pres = presentaciones.find(p => p.presentacion.toLowerCase() === (presLabel ?? '').toLowerCase());
  return { cerveza, pres, presLabel };
}

// ── Sync para una sucursal + fecha ───────────────────────────────────────────
async function syncSucursalFecha(pg, pool, briloId, pgId, nombre, fecha, cervezas, presentaciones) {
  const ventas = await fetchVentasBrilo(pool, briloId, fecha);
  if (!ventas.length) {
    console.log(`  ${nombre}: sin ventas Draft`);
    return { insertados: 0, sinMapeo: 0 };
  }

  // Buscar inventario de esa fecha (cualquier estado)
  const invRes = await pg.query(
    'SELECT id FROM merma_inventarios WHERE fecha=$1 AND sucursal_id=$2 ORDER BY id DESC LIMIT 1',
    [fecha, pgId]
  );
  const invId = invRes.rows[0]?.id ?? null;

  let insertados = 0, sinMapeo = 0;
  for (const row of ventas) {
    const { cerveza, pres, presLabel } = parsearProducto(row.producto_nombre, cervezas, presentaciones);
    if (!cerveza) { sinMapeo++; continue; }

    const ozEfectivasPorUnidad = pres ? parseFloat(pres.oz_efectivas) : null;
    const ozTotal = ozEfectivasPorUnidad !== null ? ozEfectivasPorUnidad * row.cantidad : 0;

    await pg.query(`
      INSERT INTO merma_ventas_brilo
        (inventario_id, cerveza_id, presentacion_id, presentacion_brilo, cerveza_brilo,
         cantidad, oz_efectivas, fecha, suc_id_brilo, synced_at)
      VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9, NOW())
      ON CONFLICT (fecha, suc_id_brilo, presentacion_brilo, cerveza_brilo) DO UPDATE
        SET cantidad=$6, oz_efectivas=$7, inventario_id=$1, synced_at=NOW()
    `, [invId, cerveza.id, pres?.id ?? null, presLabel ?? row.producto_nombre,
        row.producto_nombre, row.cantidad, ozTotal, fecha, briloId]);

    insertados++;
  }

  console.log(`  ${nombre} (${fecha}): ${insertados} productos ✓${sinMapeo ? `, ${sinMapeo} sin mapeo` : ''}`);
  return { insertados, sinMapeo };
}

// ── Main ─────────────────────────────────────────────────────────────────────
async function main() {
  const fechas = rangoFechas();
  console.log(`\n=== Sync Merma Ventas Brilo — Todas las sucursales ===`);
  console.log(`Fechas: ${fechas.join(', ')}`);
  console.log(`Sucursales: ${Object.values(SUCURSAL_MAP).map(s => s.nombre).join(', ')}\n`);

  console.log('Conectando a Brilo SQL Server…');
  const pool = await sql.connect(cfgMssql);
  console.log('OK');

  const pg = new Client(cfgPg);
  await pg.connect();
  console.log('PostgreSQL OK\n');

  // Cargar catálogos una sola vez
  const cervezasRes  = await pg.query("SELECT id, nombre FROM merma_cervezas WHERE estado='activo'");
  const presRes      = await pg.query('SELECT id, presentacion, oz_efectivas FROM merma_presentaciones WHERE activa=true');
  const cervezas     = cervezasRes.rows;
  const presentaciones = presRes.rows;

  let totalInsertados = 0, totalSinMapeo = 0, errores = 0;

  for (const fecha of fechas) {
    console.log(`── ${fecha} ─────────────────`);
    for (const [briloId, suc] of Object.entries(SUCURSAL_MAP)) {
      try {
        const { insertados, sinMapeo } = await syncSucursalFecha(
          pg, pool, parseInt(briloId), suc.pg_id, suc.nombre, fecha, cervezas, presentaciones
        );
        totalInsertados += insertados;
        totalSinMapeo   += sinMapeo;
      } catch (e) {
        console.error(`  ✗ ${suc.nombre} (${fecha}): ${e.message}`);
        errores++;
      }
    }
  }

  await pool.close();
  await pg.end();

  console.log(`\n══ Resumen ══`);
  console.log(`  Insertados/actualizados: ${totalInsertados}`);
  if (totalSinMapeo) console.log(`  Sin mapeo de cerveza: ${totalSinMapeo}`);
  if (errores)       console.log(`  Errores: ${errores}`);
  console.log('');
}

main().catch(e => { console.error(e.message); process.exit(1); });
