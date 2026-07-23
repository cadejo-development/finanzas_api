/**
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

 * import_ventas_mayo.js  →  import_ventas_incremental.js
 *
 * Importa ventas de TODAS las sucursales activas desde Brilo SQL Server
 * hacia PostgreSQL de forma INCREMENTAL:
 *
 * - Detecta automáticamente el último día ya importado en ventas_semanales
 * - Importa desde ese día+1 hasta AYER (hoy-1 en hora El Salvador UTC-6)
 * - No borra datos existentes; sólo inserta días nuevos
 * - Si un día ya existe para esa sucursal, lo salta (evita duplicados)
 *
 * Uso:
 *   node database/import_ventas_mayo.js           → dry-run (solo imprime)
 *   node database/import_ventas_mayo.js --apply   → inserta en PG
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const DRY_RUN  = !process.argv.includes('--apply');
const IMPORTADO_POR = 'script_sqlserver_incremental';

// Ayer en hora El Salvador (UTC-6)
function ayer() {
  const now = new Date();
  // UTC-6
  const sv = new Date(now.getTime() - 6 * 60 * 60 * 1000);
  sv.setUTCDate(sv.getUTCDate() - 1);
  return sv.toISOString().slice(0, 10);
}

// ── Mapeo Brilo sucIdOrigenSync → compras_db sucursal_id ─────────────────────
// Basado en diagnóstico: olComun.Sucursales ↔ core_db.sucursales
// ── Mapeo categorías Brilo → categoria_key del frontend ─────────────────────
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

// ── Mapeo Brilo sucIdOrigenSync → compras_db sucursal_id ─────────────────────
// Basado en diagnóstico: olComun.Sucursales ↔ core_db.sucursales
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

const cfgRst = {
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_USERNAME_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: 2033, database: 'olRestaurante',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 30000, requestTimeout: 180000 },
};
const pgConfig = {
  host: process.env.DB_HOST,
  port: 5432, database: 'compras_db',
  user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false }, connectionTimeoutMillis: 30000,
};

const hr  = (c = '─') => console.log(c.repeat(72));
const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s => console.log(`[${ts()}] ${s}`);
const d2s = d => (d instanceof Date ? d.toISOString() : String(d)).slice(0, 10);

// ── Obtener TODOS los platos del rango para TODAS las sucursales en una sola query ─
async function getAllVentas(pool, DESDE, HASTA) {
  const sucIds = Object.keys(SUCURSAL_MAP).join(',');
  const r = await pool.request().query(`
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
      AND MCT.sucIdOrigenSync IN (${sucIds})
      AND CAST(MCT.mctrstFecHoraCerrada AT TIME ZONE 'UTC'
               AT TIME ZONE 'Central America Standard Time' AS DATE)
          BETWEEN '${DESDE}' AND '${HASTA}'
      AND CPR.cprNombre IN (
        'Platos Fuertes','Platos Entradas','Platos Postres','Platos Desayunos',
        'Platos Empleados','Platos Extras Clientes','Platos Malcriadas AE2',
        'Bebidas sin Alcohol','Soda Artesanal','Agua Dura','Bebidas Malcriadas AE2 s/a',
        'Bebidas con Alcohol',
        'Cerveza Draft','Cerveza Botella','Cerveza Growler','Cervezas VR Malcriadas AE2'
      )
    GROUP BY MCT.sucIdOrigenSync,
             CAST(MCT.mctrstFecHoraCerrada AT TIME ZONE 'UTC'
                  AT TIME ZONE 'Central America Standard Time' AS DATE),
             PRO.proCodigo, PRO.proNombre, CPR.cprNombre
    ORDER BY MCT.sucIdOrigenSync, fecha, SUM(DET.dctrstCantidad * DET.dctrstPrecio) DESC
  `);

  // Agrupar en estructura: { briloId: { 'YYYY-MM-DD': [filas] } }
  const data = {};
  for (const row of r.recordset) {
    const sid  = row.suc_brilo_id;
    const fd   = d2s(row.fecha);
    if (!data[sid]) data[sid] = {};
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

// ── Insertar un día en PG ─────────────────────────────────────────────────────
async function insertarDia(pg, sucPgId, sucNombre, fecha, filas) {
  const archNombre = `import_sqlserver_${sucNombre.toLowerCase().replace(/\s+/g, '_')}_${fecha}`;

  await pg.query('BEGIN');
  try {
    const cab = await pg.query(
      `INSERT INTO ventas_semanales
         (sucursal_id, semana_inicio, archivo_nombre, importado_por, created_at, updated_at)
       VALUES ($1, $2, $3, $4, NOW(), NOW())
       RETURNING id`,
      [sucPgId, fecha, archNombre, IMPORTADO_POR]
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
  // Conectar PG primero para conocer la última fecha importada
  const pool = new Pool(pgConfig);
  const pg   = await pool.connect();

  const ultimaRes = await pg.query(
    `SELECT MAX(semana_inicio) AS ultima FROM ventas_semanales`
  );
  const ultimaFecha = ultimaRes.rows[0].ultima
    ? d2s(new Date(ultimaRes.rows[0].ultima))
    : '2026-04-30'; // fallback: si no hay nada, empieza desde 01 mayo

  const HASTA = ayer();

  // Siguiente día después del último ya importado
  const desdeDate = new Date(ultimaFecha + 'T12:00:00Z');
  desdeDate.setUTCDate(desdeDate.getUTCDate() + 1);
  const DESDE = d2s(desdeDate);

  hr('═');
  log(`IMPORTACIÓN VENTAS INCREMENTAL — ${DESDE} a ${HASTA}`);
  log(`Último día importado: ${ultimaFecha}`);
  log(DRY_RUN ? '⚠  MODO DRY-RUN (sin cambios en BD)' : '🚀 MODO APPLY — insertando en PostgreSQL');
  hr('═');

  if (DESDE > HASTA) {
    log(`✅ Todo al día. Nada nuevo que importar (último: ${ultimaFecha}, ayer: ${HASTA}).`);
    pg.release(); await pool.end();
    return;
  }

  // Generar lista de fechas a importar
  const fechas = [];
  for (let d = new Date(DESDE + 'T12:00:00Z'); d2s(d) <= HASTA; d.setUTCDate(d.getUTCDate() + 1)) {
    fechas.push(d2s(d));
  }
  log(`Fechas a importar: ${fechas.join(', ')}\n`);

  log('Conectando Brilo SQL Server...');
  const poolRst = await new sql.ConnectionPool(cfgRst).connect();
  log('   OK');
  log('Ejecutando query batch (todas las sucursales, todo el rango)...');
  const dataPorSuc = await getAllVentas(poolRst, DESDE, HASTA);
  await poolRst.close();

  // Mostrar resumen de lo jalado
  let totalFilas = 0;
  hr();

  if (DRY_RUN) {
    log('DRY-RUN completado. Para aplicar: node database/import_ventas_mayo.js --apply');
    pg.release(); await pool.end();
    return;
  }

  // Verificar qué días ya existen en PG para no duplicar
  const existentesRes = await pg.query(
    `SELECT sucursal_id, semana_inicio::text AS fecha FROM ventas_semanales`
  );
  const yaExisten = new Set(
    existentesRes.rows.map(r => `${r.sucursal_id}|${d2s(new Date(r.fecha))}`)
  );
  log(`Días ya existentes en PG: ${existentesRes.rowCount}`);

  // Insertar
  hr();
  log('Insertando...\n');
  let insertadosCabs  = 0;
  let insertadasFilas = 0;
  let granTotal       = 0;

  for (const [briloIdStr, suc] of Object.entries(SUCURSAL_MAP)) {
    const briloId = Number(briloIdStr);
    log(`  ▸ ${suc.nombre}`);

    for (const fecha of fechas) {
      const filas = (dataPorSuc[briloId] || {})[fecha] || [];
      const clave = `${suc.pg_id}|${fecha}`;
      if (yaExisten.has(clave)) {
        console.log(`     ${fecha} → ya existe, se salta`);
        continue;
      }
      if (filas.length === 0) {
        console.log(`     ${fecha} → sin ventas, se omite`);
        continue;
      }
      const res = await insertarDia(pg, suc.pg_id, suc.nombre, fecha, filas);
      insertadosCabs++;
      insertadasFilas += res.filas;
      granTotal       += res.total;
      console.log(`     ${fecha} → id=${res.ventaId}, ${res.filas} platos, $${res.total.toFixed(2)}`);
    }
  }

  pg.release();
  await pool.end();

  hr('═');
  log(`✅ COMPLETADO`);
  log(`   Cabeceras insertadas : ${insertadosCabs}`);
  log(`   Filas detalle        : ${insertadasFilas}`);
  log(`   Venta total          : $${granTotal.toFixed(2)}`);
  hr('═');
}

main().catch(e => {
  console.error('\nERROR FATAL:', e.message);
  if (e.message?.includes('ECONNREFUSED') || e.message?.includes('timeout')) {
    console.error('→ Verifica que la VPN esté activa');
  }
  process.exit(1);
});
