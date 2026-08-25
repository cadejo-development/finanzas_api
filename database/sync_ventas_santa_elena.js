require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

/**
 * sync_ventas_santa_elena.js
 *
 * Sync diario de ventas de RESTAURANTE SANTA ELENA
 * desde Brilo (olRestaurante, SQL Server) → compras_db (PostgreSQL).
 *
 * - Procesa los últimos DIAS_ATRAS días hasta ayer (hora El Salvador).
 * - Idempotente: salta fechas que ya existen en ventas_semanales.
 * - Diseñado para correr diario vía GitHub Actions (requiere VPN CLIN).
 *
 * Uso:
 *   node database/sync_ventas_santa_elena.js           → aplica
 *   node database/sync_ventas_santa_elena.js --dry-run → simula sin escribir
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const DRY_RUN = process.argv.includes('--dry-run');
// --dias=N para backfill manual; por defecto 7 días (cron diario)
const diasArg = (process.argv.find(a => a.startsWith('--dias=')) || '').replace('--dias=', '');
const DIAS_ATRAS = diasArg ? parseInt(diasArg, 10) : 7;
const SUC_BRILO_ID = 11;
const SUC_PG_ID    = 8;

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

async function getVentas(poolRst, desde, hasta) {
  const r = await poolRst.request().query(`
    SELECT
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
      AND MCT.sucIdOrigenSync = ${SUC_BRILO_ID}
      AND CAST(MCT.mctrstFecHoraCerrada AT TIME ZONE 'UTC'
               AT TIME ZONE 'Central America Standard Time' AS DATE)
          BETWEEN '${desde}' AND '${hasta}'
      AND CPR.cprNombre IN (
        'Platos Fuertes','Platos Entradas','Platos Postres','Platos Desayunos',
        'Platos Empleados','Platos Extras Clientes',
        'Bebidas sin Alcohol','Soda Artesanal','Agua Dura',
        'Bebidas con Alcohol',
        'Cerveza Draft','Cerveza Botella','Cerveza Growler'
      )
    GROUP BY
      CAST(MCT.mctrstFecHoraCerrada AT TIME ZONE 'UTC'
           AT TIME ZONE 'Central America Standard Time' AS DATE),
      PRO.proCodigo, PRO.proNombre, CPR.cprNombre
    ORDER BY fecha, SUM(DET.dctrstCantidad * DET.dctrstPrecio) DESC
  `);

  const porFecha = {};
  for (const row of r.recordset) {
    const fd = d2s(row.fecha);
    if (!porFecha[fd]) porFecha[fd] = [];
    porFecha[fd].push({
      producto_codigo:  (row.producto_codigo ?? '').trim().slice(0, 50),
      producto_nombre:  (row.producto_nombre ?? '').trim().slice(0, 200),
      categoria_key:    CATEGORIA_MAP[row.categoria_brilo] ?? 'otros',
      cantidad_vendida: Number(row.cantidad_vendida) || 0,
      precio_unitario:  Number(row.precio_unitario)  || 0,
      total:            Number(row.total)            || 0,
    });
  }
  return porFecha;
}

async function insertarDia(pg, fecha, filas) {
  await pg.query('BEGIN');
  try {
    const cab = await pg.query(
      `INSERT INTO ventas_semanales
         (sucursal_id, semana_inicio, archivo_nombre, importado_por, created_at, updated_at)
       VALUES ($1, $2, $3, $4, NOW(), NOW())
       RETURNING id`,
      [SUC_PG_ID, fecha, `sync_diario_${fecha}`, 'sync_ventas_santa_elena']
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

async function main() {
  const HASTA = ayer();
  const DESDE = hace(DIAS_ATRAS);

  console.log('═'.repeat(64));
  log(`SYNC VENTAS SANTA ELENA — ${DESDE} a ${HASTA}`);
  log(DRY_RUN ? '⚠  DRY-RUN (sin cambios en BD)' : '*** MODO REAL ***');
  console.log('═'.repeat(64));

  const pool = new Pool(pgConfig);
  const pg   = await pool.connect();

  const existentesRes = await pg.query(
    `SELECT semana_inicio::text AS fecha FROM ventas_semanales WHERE sucursal_id = $1`,
    [SUC_PG_ID]
  );
  const yaExisten = new Set(existentesRes.rows.map(r => d2s(new Date(r.fecha))));
  log(`Días ya existentes para Santa Elena: ${yaExisten.size}`);

  // Fechas a procesar
  const fechas = [];
  for (let d = new Date(DESDE + 'T12:00:00Z'); d2s(d) <= HASTA; d.setUTCDate(d.getUTCDate() + 1)) {
    fechas.push(d2s(d));
  }

  const pendientes = fechas.filter(f => !yaExisten.has(f));
  log(`Fechas en rango: ${fechas.length} | Nuevas a insertar: ${pendientes.length} | Ya existentes: ${fechas.length - pendientes.length}`);

  if (pendientes.length === 0) {
    log('Nada que sincronizar — todos los días ya existen.');
    pg.release(); await pool.end();
    return;
  }

  if (DRY_RUN) {
    log('Fechas que se insertarían: ' + pendientes.join(', '));
    log('DRY-RUN completado. Para aplicar omite --dry-run.');
    pg.release(); await pool.end();
    return;
  }

  log('Conectando Brilo SQL Server...');
  const poolRst = await new sql.ConnectionPool(cfgRst).connect();
  log('   SQL Server OK\n');

  let porFecha;
  try {
    porFecha = await getVentas(poolRst, DESDE, HASTA);
  } catch (e) {
    log(`✗ Error en SQL Server: ${e.message}`);
    await poolRst.close();
    pg.release(); await pool.end();
    process.exit(1);
  }

  let totalCabs = 0, totalFilas = 0, totalVenta = 0;

  for (const fecha of pendientes) {
    const filas = porFecha[fecha] || [];
    if (filas.length === 0) {
      log(`  ${fecha} → sin ventas en Brilo`);
      continue;
    }
    try {
      const res = await insertarDia(pg, fecha, filas);
      totalCabs++;
      totalFilas += res.filas;
      totalVenta += res.total;
      log(`  ${fecha} → ✓ id=${res.ventaId}, ${res.filas} platos, $${res.total.toFixed(2)}`);
    } catch (e) {
      log(`  ${fecha} → ✗ Error: ${e.message}`);
    }
  }

  console.log('═'.repeat(64));
  log(`RESUMEN: ${totalCabs} días insertados | ${totalFilas} líneas | $${totalVenta.toFixed(2)}`);
  console.log('═'.repeat(64));

  await poolRst.close();
  pg.release(); await pool.end();
}

main().catch(e => { console.error('Error fatal:', e.message); process.exit(1); });
