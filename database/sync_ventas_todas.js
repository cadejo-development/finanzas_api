require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

/**
 * sync_ventas_todas.js
 *
 * Sync diario de ventas de TODAS las sucursales activas
 * desde Brilo (olRestaurante, SQL Server) → compras_db (PostgreSQL).
 *
 * - Procesa los últimos DIAS_ATRAS días hasta ayer (hora El Salvador UTC-6).
 * - Idempotente: salta fechas que ya existen en ventas_semanales por sucursal.
 * - Una sola query a SQL Server para todas las sucursales (eficiente).
 * - Diseñado para correr diario vía GitHub Actions (requiere VPN CLIN).
 *
 * Argumentos:
 *   --dry-run         Simula sin escribir en BD
 *   --dias=N          Días hacia atrás a procesar (default: 7)
 *   --suc=N           Solo sincronizar la sucursal con pg_id=N (default: todas)
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const DRY_RUN = process.argv.includes('--dry-run');
const diasArg = (process.argv.find(a => a.startsWith('--dias=')) || '').replace('--dias=', '');
const sucArg  = (process.argv.find(a => a.startsWith('--suc='))  || '').replace('--suc=',  '');
const DIAS_ATRAS  = diasArg ? parseInt(diasArg, 10) : 7;
const FILTRO_PG   = sucArg  ? parseInt(sucArg,  10) : null;

// ── Mapeo Brilo sucIdOrigenSync → compras_db sucursal_id ─────────────────────
const SUCURSAL_MAP = {
   3: { pg_id:  1, nombre: 'RESTAURANTE ZONA ROSA'    },
   6: { pg_id:  3, nombre: 'RESTAURANTE LA LIBERTAD'  },
   7: { pg_id:  4, nombre: 'RESTAURANTE AEROPUERTO 1' },
   8: { pg_id:  5, nombre: 'RESTAURANTE AEROPUERTO 2' },
  10: { pg_id:  7, nombre: 'RESTAURANTE PASEO VENECIA'},
  11: { pg_id:  8, nombre: 'RESTAURANTE SANTA ELENA'  },
  12: { pg_id:  9, nombre: 'RESTAURANTE HUIZUCAR'     },
  13: { pg_id: 10, nombre: 'RESTAURANTE OPICO'        },
  19: { pg_id: 11, nombre: 'RESTAURANTE CASA GUIROLA' },
};

const CATEGORIA_MAP = {
  'Platos Fuertes':             'platos_fuertes',
  'Platos Entradas':            'entradas',
  'Platos Postres':             'postres',
  'Platos Desayunos':           'desayunos',
  'Platos Empleados':           'platos_fuertes',
  'Platos Extras Clientes':     'platos_fuertes',
  'Platos Malcriadas AE2':      'platos_fuertes',
  'Bebidas sin Alcohol':        'bebidas_sin_alcohol',
  'Soda Artesanal':             'bebidas_sin_alcohol',
  'Agua Dura':                  'bebidas_sin_alcohol',
  'Bebidas Malcriadas AE2 s/a': 'bebidas_sin_alcohol',
  'Bebidas con Alcohol':        'bebidas_alcohol',
  'Cerveza Draft':              'cerveza',
  'Cerveza Botella':            'cerveza',
  'Cerveza Growler':            'cerveza',
  'Cervezas VR Malcriadas AE2': 'cerveza',
};

const cfgRst = {
  user:     process.env.DB_USERNAME_ORIGEN,
  password: process.env.DB_PASSWORD_ORIGEN,
  server:   process.env.DB_HOST_ORIGEN,
  port:     2033,
  database: 'olRestaurante',
  options:  { trustServerCertificate: true, encrypt: false, connectTimeout: 30000, requestTimeout: 300000 },
};

const pgConfig = {
  host:     process.env.DB_HOST,
  port:     5432,
  database: process.env.DB_DATABASE_COMPRAS,
  user:     process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  ssl:      { rejectUnauthorized: false },
};

const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s  => console.log(`[${ts()}] ${s}`);
const d2s = d  => (d instanceof Date ? d.toISOString() : String(d)).slice(0, 10);

function svHoy() {
  const now = new Date();
  return new Date(now.getTime() - 6 * 60 * 60 * 1000);
}
function ayer() {
  const sv = svHoy();
  sv.setUTCDate(sv.getUTCDate() - 1);
  return sv.toISOString().slice(0, 10);
}
function hace(dias) {
  const sv = svHoy();
  sv.setUTCDate(sv.getUTCDate() - dias);
  return sv.toISOString().slice(0, 10);
}

// ── Obtener ventas de todas las sucursales en un solo round-trip ──────────────
async function getVentas(poolRst, sucIds, desde, hasta) {
  const r = await poolRst.request().query(`
    SELECT
      MCT.sucIdOrigenSync                                AS suc_brilo_id,
      CAST(MCT.mctrstFecHoraCerrada AT TIME ZONE 'UTC'
           AT TIME ZONE 'Central America Standard Time' AS DATE) AS fecha,
      LTRIM(RTRIM(PRO.proCodigo))                        AS producto_codigo,
      LTRIM(RTRIM(PRO.proNombre))                        AS producto_nombre,
      LTRIM(RTRIM(CPR.cprNombre))                        AS categoria_brilo,
      SUM(DET.dctrstCantidad)                            AS cantidad_vendida,
      AVG(DET.dctrstPrecio)                              AS precio_unitario,
      SUM(DET.dctrstCantidad * DET.dctrstPrecio)         AS total
    FROM olRestaurante.dbo.maeCuentasRst MCT WITH (NOLOCK)
    INNER JOIN olRestaurante.dbo.detCuentasRst  DET WITH (NOLOCK) ON DET.mctrstId = MCT.mctrstId
    INNER JOIN olComun.dbo.Productos            PRO WITH (NOLOCK) ON PRO.proId    = DET.proId
    LEFT  JOIN olComun.dbo.CategoriasProductos  CPR WITH (NOLOCK) ON CPR.cprId   = PRO.cprId
    WHERE MCT.mctrstEliminado = 0
      AND DET.dctrstEliminado = 0
      AND DET.dctrstIdModificadorDe IS NULL
      AND MCT.sucIdOrigenSync IN (${sucIds.join(',')})
      AND CAST(MCT.mctrstFecHoraCerrada AT TIME ZONE 'UTC'
               AT TIME ZONE 'Central America Standard Time' AS DATE)
          BETWEEN '${desde}' AND '${hasta}'
      AND CPR.cprNombre IN (
        'Platos Fuertes','Platos Entradas','Platos Postres','Platos Desayunos',
        'Platos Empleados','Platos Extras Clientes','Platos Malcriadas AE2',
        'Bebidas sin Alcohol','Soda Artesanal','Agua Dura','Bebidas Malcriadas AE2 s/a',
        'Bebidas con Alcohol',
        'Cerveza Draft','Cerveza Botella','Cerveza Growler','Cervezas VR Malcriadas AE2'
      )
    GROUP BY
      MCT.sucIdOrigenSync,
      CAST(MCT.mctrstFecHoraCerrada AT TIME ZONE 'UTC'
           AT TIME ZONE 'Central America Standard Time' AS DATE),
      PRO.proCodigo, PRO.proNombre, CPR.cprNombre
    ORDER BY MCT.sucIdOrigenSync, fecha,
             SUM(DET.dctrstCantidad * DET.dctrstPrecio) DESC
  `);

  // Estructura: { briloId: { 'YYYY-MM-DD': [filas] } }
  const data = {};
  for (const row of r.recordset) {
    const sid = row.suc_brilo_id;
    const fd  = d2s(row.fecha);
    if (!data[sid])     data[sid]     = {};
    if (!data[sid][fd]) data[sid][fd] = [];
    data[sid][fd].push({
      producto_codigo:  (row.producto_codigo ?? '').trim().slice(0, 50),
      producto_nombre:  (row.producto_nombre ?? '').trim().slice(0, 200),
      categoria_key:    CATEGORIA_MAP[row.categoria_brilo] ?? 'otros',
      cantidad_vendida: Number(row.cantidad_vendida) || 0,
      precio_unitario:  Number(row.precio_unitario)  || 0,
      total:            Number(row.total)            || 0,
    });
  }
  return data;
}

// ── Insertar un día para una sucursal ─────────────────────────────────────────
async function insertarDia(pg, sucPgId, sucNombre, fecha, filas) {
  const archNombre = `sync_diario_${sucNombre.toLowerCase().replace(/\s+/g, '_')}_${fecha}`;

  await pg.query('BEGIN');
  try {
    const cab = await pg.query(
      `INSERT INTO ventas_semanales
         (sucursal_id, semana_inicio, archivo_nombre, importado_por, created_at, updated_at)
       VALUES ($1, $2, $3, $4, NOW(), NOW())
       RETURNING id`,
      [sucPgId, fecha, archNombre, 'sync_ventas_todas']
    );
    const ventaId = cab.rows[0].id;

    if (filas.length > 0) {
      const BATCH = 100;
      for (let i = 0; i < filas.length; i += BATCH) {
        const chunk  = filas.slice(i, i + BATCH);
        const params = [];
        const ph     = chunk.map(f => {
          const o = params.length;
          params.push(ventaId, f.producto_codigo, f.producto_nombre, f.categoria_key,
                      f.cantidad_vendida, f.precio_unitario, f.total);
          return `($${o+1},$${o+2},$${o+3},$${o+4},$${o+5},$${o+6},$${o+7},NOW(),NOW())`;
        });
        await pg.query(
          `INSERT INTO ventas_semanales_detalle
             (venta_semanal_id, producto_codigo, producto_nombre, categoria_key,
              cantidad_vendida, precio_unitario, total, created_at, updated_at)
           VALUES ${ph.join(',')}`,
          params
        );
      }
    }

    await pg.query('COMMIT');
    return { ventaId, filas: filas.length, total: filas.reduce((s, f) => s + f.total, 0) };
  } catch (e) {
    await pg.query('ROLLBACK');
    throw e;
  }
}

// ── Main ──────────────────────────────────────────────────────────────────────
async function main() {
  const HASTA = ayer();
  const DESDE = hace(DIAS_ATRAS);

  // Filtrar sucursales si se pasó --suc=N
  const sucursalesActivas = Object.entries(SUCURSAL_MAP)
    .filter(([, v]) => FILTRO_PG === null || v.pg_id === FILTRO_PG);

  if (sucursalesActivas.length === 0) {
    console.error(`No se encontró sucursal con pg_id=${FILTRO_PG} en SUCURSAL_MAP.`);
    process.exit(1);
  }

  console.log('═'.repeat(72));
  log(`SYNC VENTAS TODAS LAS SUCURSALES — ${DESDE} a ${HASTA}`);
  log(DRY_RUN ? '⚠  DRY-RUN (sin cambios en BD)' : '*** MODO REAL ***');
  if (FILTRO_PG) log(`Filtrando solo pg_id=${FILTRO_PG}`);
  log(`Sucursales: ${sucursalesActivas.map(([, v]) => v.nombre).join(', ')}`);
  console.log('═'.repeat(72));

  const pool = new Pool(pgConfig);
  const pg   = await pool.connect();

  // Fechas del rango
  const fechas = [];
  for (let d = new Date(DESDE + 'T12:00:00Z'); d2s(d) <= HASTA; d.setUTCDate(d.getUTCDate() + 1)) {
    fechas.push(d2s(d));
  }

  // Días ya existentes por sucursal_pg_id
  const pgIds = sucursalesActivas.map(([, v]) => v.pg_id);
  const existRes = await pg.query(
    `SELECT sucursal_id, semana_inicio::text AS fecha
     FROM ventas_semanales
     WHERE sucursal_id = ANY($1)`,
    [pgIds]
  );
  const yaExisten = {};  // { pg_id: Set<fecha> }
  for (const row of existRes.rows) {
    const sid = row.sucursal_id;
    if (!yaExisten[sid]) yaExisten[sid] = new Set();
    yaExisten[sid].add(d2s(new Date(row.fecha)));
  }

  // Reporte de pendientes
  for (const [briloId, suc] of sucursalesActivas) {
    const existentes = yaExisten[suc.pg_id] || new Set();
    const pendientes = fechas.filter(f => !existentes.has(f));
    log(`${suc.nombre}: ${pendientes.length} fechas nuevas / ${fechas.length - pendientes.length} ya existentes`);
  }

  const totalPendientes = sucursalesActivas.reduce((sum, [, suc]) => {
    const existentes = yaExisten[suc.pg_id] || new Set();
    return sum + fechas.filter(f => !existentes.has(f)).length;
  }, 0);

  if (totalPendientes === 0) {
    log('Nada que sincronizar — todos los días ya existen para todas las sucursales.');
    pg.release(); await pool.end();
    return;
  }

  if (DRY_RUN) {
    log('DRY-RUN completado. Para aplicar omite --dry-run.');
    pg.release(); await pool.end();
    return;
  }

  // Conectar SQL Server y traer ventas en un solo round-trip
  log('Conectando Brilo SQL Server...');
  const sucIds = sucursalesActivas.map(([id]) => Number(id));
  const poolRst = await new sql.ConnectionPool(cfgRst).connect();
  log('   SQL Server OK\n');

  let ventas;
  try {
    ventas = await getVentas(poolRst, sucIds, DESDE, HASTA);
  } catch (e) {
    log(`✗ Error en SQL Server: ${e.message}`);
    await poolRst.close();
    pg.release(); await pool.end();
    process.exit(1);
  }
  await poolRst.close();

  // Insertar por sucursal
  let totalCabs = 0, totalFilas = 0, totalVenta = 0;

  for (const [briloId, suc] of sucursalesActivas) {
    const existentes = yaExisten[suc.pg_id] || new Set();
    const pendientes = fechas.filter(f => !existentes.has(f));
    const dataSuc    = ventas[Number(briloId)] || {};

    console.log('');
    log(`── ${suc.nombre} (${pendientes.length} fechas pendientes)`);

    for (const fecha of pendientes) {
      const filas = dataSuc[fecha] || [];
      if (filas.length === 0) {
        log(`  ${fecha} → sin ventas en Brilo`);
        continue;
      }
      try {
        const res = await insertarDia(pg, suc.pg_id, suc.nombre, fecha, filas);
        totalCabs++;
        totalFilas += res.filas;
        totalVenta += res.total;
        log(`  ${fecha} → ✓ id=${res.ventaId}, ${res.filas} productos, $${res.total.toFixed(2)}`);
      } catch (e) {
        log(`  ${fecha} → ✗ Error: ${e.message}`);
      }
    }
  }

  console.log('');
  console.log('═'.repeat(72));
  log(`RESUMEN: ${totalCabs} días insertados | ${totalFilas} líneas | $${totalVenta.toFixed(2)}`);
  console.log('═'.repeat(72));

  pg.release();
  await pool.end();
}

main().catch(e => { console.error('Error fatal:', e.message); process.exit(1); });
