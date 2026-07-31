require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

/**
 * import_ventas_santa_elena_2025.js
 *
 * Importación masiva de ventas de RESTAURANTE SANTA ELENA
 * desde 2025-01-01 hasta ayer (hora El Salvador UTC-6).
 *
 * Procesa en chunks de 30 días para evitar timeout en SQL Server.
 * Es idempotente: salta días que ya existen en ventas_semanales.
 *
 * Uso:
 *   node database/import_ventas_santa_elena_2025.js           → dry-run
 *   node database/import_ventas_santa_elena_2025.js --apply   → inserta en PG
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const DRY_RUN       = !process.argv.includes('--apply');
const IMPORTADO_POR = 'script_bulk_santa_elena_2025';
const DESDE_FIJO    = '2025-01-01';
const CHUNK_DIAS    = 30;          // Días por batch a SQL Server

// Sucursal objetivo
const SUC_BRILO_ID = 11;
const SUC_PG_ID    = 8;
const SUC_NOMBRE   = 'RESTAURANTE SANTA ELENA';

// Mapeo categorías Brilo → categoria_key
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

// Conexiones
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
  database: 'compras_db',
  user:     process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  ssl:      { rejectUnauthorized: false },
  connectionTimeoutMillis: 30000,
};

// Helpers
const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s  => console.log(`[${ts()}] ${s}`);
const hr  = (c = '─') => console.log(c.repeat(72));
const d2s = d  => (d instanceof Date ? d.toISOString() : String(d)).slice(0, 10);

function ayer() {
  const now = new Date();
  const sv  = new Date(now.getTime() - 6 * 60 * 60 * 1000);
  sv.setUTCDate(sv.getUTCDate() - 1);
  return sv.toISOString().slice(0, 10);
}

function addDays(fechaStr, n) {
  const d = new Date(fechaStr + 'T12:00:00Z');
  d.setUTCDate(d.getUTCDate() + n);
  return d2s(d);
}

// Genera chunks de 30 días entre DESDE y HASTA
function generarChunks(desde, hasta) {
  const chunks = [];
  let cur = desde;
  while (cur <= hasta) {
    const fin = addDays(cur, CHUNK_DIAS - 1);
    chunks.push({ desde: cur, hasta: fin > hasta ? hasta : fin });
    cur = addDays(fin > hasta ? hasta : fin, 1);
  }
  return chunks;
}

// Query a SQL Server para un rango específico
async function getVentasChunk(poolRst, desde, hasta) {
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

  // Agrupar por fecha
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

// Insertar un día en PG (dentro de transacción)
async function insertarDia(pg, fecha, filas) {
  const archNombre = `bulk_santa_elena_${fecha}`;
  await pg.query('BEGIN');
  try {
    const cab = await pg.query(
      `INSERT INTO ventas_semanales
         (sucursal_id, semana_inicio, archivo_nombre, importado_por, created_at, updated_at)
       VALUES ($1, $2, $3, $4, NOW(), NOW())
       RETURNING id`,
      [SUC_PG_ID, fecha, archNombre, IMPORTADO_POR]
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

  hr('═');
  log(`IMPORTACIÓN MASIVA SANTA ELENA — ${DESDE_FIJO} a ${HASTA}`);
  log(DRY_RUN ? '⚠  MODO DRY-RUN (sin cambios en BD)' : '🚀 MODO APPLY — insertando en PostgreSQL');
  hr('═');

  // Conectar PG
  const pool = new Pool(pgConfig);
  const pg   = await pool.connect();

  // Días ya existentes para Santa Elena
  const existentesRes = await pg.query(
    `SELECT semana_inicio::text AS fecha FROM ventas_semanales WHERE sucursal_id = $1`,
    [SUC_PG_ID]
  );
  const yaExisten = new Set(existentesRes.rows.map(r => d2s(new Date(r.fecha))));
  log(`Días ya existentes para Santa Elena en PG: ${yaExisten.size}`);

  // Generar chunks
  const chunks = generarChunks(DESDE_FIJO, HASTA);
  log(`Chunks a procesar: ${chunks.length} (${CHUNK_DIAS} días c/u)`);
  hr();

  if (DRY_RUN) {
    log('Chunks que se procesarían:');
    chunks.forEach((c, i) => log(`  ${i + 1}. ${c.desde} → ${c.hasta}`));
    log('\nDRY-RUN completado. Para aplicar: node database/import_ventas_santa_elena_2025.js --apply');
    pg.release(); await pool.end();
    return;
  }

  // Conectar SQL Server
  log('Conectando Brilo SQL Server...');
  const poolRst = await new sql.ConnectionPool(cfgRst).connect();
  log('   OK\n');

  let totalCabs  = 0;
  let totalFilas = 0;
  let totalVenta = 0;
  let saltados   = 0;

  for (let ci = 0; ci < chunks.length; ci++) {
    const { desde, hasta } = chunks[ci];
    log(`Chunk ${ci + 1}/${chunks.length}: ${desde} → ${hasta}`);

    let porFecha;
    try {
      porFecha = await getVentasChunk(poolRst, desde, hasta);
    } catch (e) {
      log(`  ✗ Error en SQL Server: ${e.message}`);
      continue;
    }

    // Generar lista de fechas de este chunk
    const fechasChunk = [];
    for (let d = new Date(desde + 'T12:00:00Z'); d2s(d) <= hasta; d.setUTCDate(d.getUTCDate() + 1)) {
      fechasChunk.push(d2s(d));
    }

    for (const fecha of fechasChunk) {
      if (yaExisten.has(fecha)) {
        process.stdout.write(`  ${fecha} → ya existe\n`);
        saltados++;
        continue;
      }
      const filas = porFecha[fecha] || [];
      if (filas.length === 0) {
        process.stdout.write(`  ${fecha} → sin ventas\n`);
        continue;
      }
      try {
        const res = await insertarDia(pg, fecha, filas);
        totalCabs++;
        totalFilas += res.filas;
        totalVenta += res.total;
        yaExisten.add(fecha); // evitar doble insert si hay solapamiento de chunks
        process.stdout.write(`  ${fecha} → ✓ id=${res.ventaId}, ${res.filas} platos, $${res.total.toFixed(2)}\n`);
      } catch (e) {
        log(`  ✗ Error insertando ${fecha}: ${e.message}`);
      }
    }
    hr();
  }

  await poolRst.close();
  pg.release();
  await pool.end();

  hr('═');
  log(`✅ IMPORTACIÓN COMPLETADA`);
  log(`   Sucursal         : ${SUC_NOMBRE} (pg_id=${SUC_PG_ID})`);
  log(`   Días insertados  : ${totalCabs}`);
  log(`   Días saltados    : ${saltados} (ya existían)`);
  log(`   Filas detalle    : ${totalFilas}`);
  log(`   Venta total      : $${totalVenta.toFixed(2)}`);
  hr('═');
}

main().catch(e => {
  console.error('\nERROR FATAL:', e.message);
  if (e.message?.includes('ECONNREFUSED') || e.message?.includes('timeout')) {
    console.error('→ Verifica que la VPN/conexión a Brilo esté activa');
  }
  process.exit(1);
});
