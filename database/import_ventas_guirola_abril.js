/**
 * import_ventas_guirola_abril.js
 *
 * Importa ventas de la ÚLTIMA SEMANA DE ABRIL 2026 (27-30 abril) de
 * RESTAURANTE CASA GUIROLA desde SQL Server (olRestaurante) hacia
 * ventas_semanales / ventas_semanales_detalle en PostgreSQL (compras_db).
 *
 * Solo PLATOS FUERTES. Importa un registro por día (4 entradas)
 * para permitir vista de ventas por día en el panel.
 *
 * Uso:
 *   node import_ventas_guirola_abril.js           → dry-run (solo imprime)
 *   node import_ventas_guirola_abril.js --apply   → inserta en PostgreSQL
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const DRY_RUN = !process.argv.includes('--apply');

// ── Constantes ────────────────────────────────────────────────────────────────
const SUC_SQLSERVER_ID = 19;           // sucIdOrigenSync para CASA GUIROLA en SQL Server
const SUC_PG_ID        = 11;           // sucursal_id en compras_db (SUC-GU)
const DIAS             = ['2026-04-27', '2026-04-28', '2026-04-29', '2026-04-30'];
const IMPORTADO_POR    = 'script_sqlserver';

// ── Conexiones ────────────────────────────────────────────────────────────────
const cfgRst = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olRestaurante',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};
const pgConfig = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432, database: 'compras_db',
  user: 'cadejo_admin', password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
  connectionTimeoutMillis: 30000,
};

const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s => console.log(`[${ts()}] ${s}`);
const hr  = () => console.log('─'.repeat(70));

// ── Ventas de SQL Server para un día específico ───────────────────────────────
async function getVentasDia(pool, fecha) {
  const r = await pool.request().query(`
    SELECT
      PRO.proCodigo                              AS producto_codigo,
      PRO.proNombre                              AS producto_nombre,
      SUM(DET.dctrstCantidad)                    AS cantidad_vendida,
      AVG(DET.dctrstPrecio)                      AS precio_unitario,
      SUM(DET.dctrstCantidad * DET.dctrstPrecio) AS total
    FROM olRestaurante.dbo.maeCuentasRst MCT WITH (NOLOCK)
    INNER JOIN olRestaurante.dbo.detCuentasRst  DET WITH (NOLOCK) ON DET.mctrstId  = MCT.mctrstId
    INNER JOIN olComun.dbo.Productos            PRO WITH (NOLOCK) ON PRO.proId     = DET.proId
    LEFT  JOIN olComun.dbo.CategoriasProductos  CPR WITH (NOLOCK) ON CPR.cprId     = PRO.cprId
    WHERE MCT.mctrstEliminado = 0
      AND DET.dctrstEliminado = 0
      AND DET.dctrstIdModificadorDe IS NULL
      AND MCT.sucIdOrigenSync = ${SUC_SQLSERVER_ID}
      AND CAST(MCT.mctrstFecHoraCerrada AT TIME ZONE 'UTC' AT TIME ZONE 'Central America Standard Time' AS DATE) = '${fecha}'
      AND CPR.cprNombre LIKE '%latos%uertes%'
    GROUP BY PRO.proCodigo, PRO.proNombre
    ORDER BY SUM(DET.dctrstCantidad * DET.dctrstPrecio) DESC
  `);

  return r.recordset.map(row => ({
    producto_codigo:  (row.producto_codigo  ?? '').trim(),
    producto_nombre:  (row.producto_nombre  ?? '').trim().slice(0, 200),
    categoria_key:    'platos_fuertes',
    cantidad_vendida: Number(row.cantidad_vendida),
    precio_unitario:  Number(row.precio_unitario),
    total:            Number(row.total),
  }));
}

// ── Insertar un día en PostgreSQL ─────────────────────────────────────────────
async function insertarDia(pg, fecha, filas) {
  const archNombre = `import_sqlserver_guirola_${fecha}.auto`;

  const existe = await pg.query(
    `SELECT id FROM ventas_semanales WHERE sucursal_id = $1 AND semana_inicio = $2`,
    [SUC_PG_ID, fecha]
  );
  if (existe.rows.length > 0) {
    log(`  AVISO: ya existe un registro para ${fecha} (id=${existe.rows[0].id}) — se omite.`);
    return;
  }

  await pg.query('BEGIN');
  try {
    const cab = await pg.query(
      `INSERT INTO ventas_semanales (sucursal_id, semana_inicio, archivo_nombre, importado_por, created_at, updated_at)
       VALUES ($1, $2, $3, $4, NOW(), NOW()) RETURNING id`,
      [SUC_PG_ID, fecha, archNombre, IMPORTADO_POR]
    );
    const ventaId = cab.rows[0].id;

    if (filas.length > 0) {
      const BATCH = 100;
      for (let i = 0; i < filas.length; i += BATCH) {
        const chunk = filas.slice(i, i + BATCH);
        const params = [];
        const ph = chunk.map(f => {
          const b = params.length;
          params.push(ventaId, f.producto_codigo, f.producto_nombre, f.categoria_key,
                      f.cantidad_vendida, f.precio_unitario, f.total);
          return `($${b+1},$${b+2},$${b+3},$${b+4},$${b+5},$${b+6},$${b+7},NOW(),NOW())`;
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
    log(`  ${fecha} → id=${ventaId}, ${filas.length} platos insertados`);
  } catch (e) {
    await pg.query('ROLLBACK');
    log(`  ERROR en ${fecha} — ROLLBACK`);
    throw e;
  }
}

// ── Main ──────────────────────────────────────────────────────────────────────
async function main() {
  hr();
  log('IMPORTACIÓN VENTAS GUIROLA — Platos Fuertes — 27-30 Abril 2026');
  log(DRY_RUN ? 'MODO DRY-RUN' : 'MODO APPLY');
  hr();

  log('Conectando SQL Server...');
  const poolRst = await sql.connect(cfgRst);

  const datosPorDia = {};
  for (const fecha of DIAS) {
    const filas = await getVentasDia(poolRst, fecha);
    datosPorDia[fecha] = filas;
    log(`${fecha} → ${filas.length} platos, $${filas.reduce((s,f)=>s+f.total,0).toFixed(2)}`);
  }

  await poolRst.close();
  hr();

  // Resumen en tabla
  const todosPlatos = [...new Set(Object.values(datosPorDia).flat().map(f => f.producto_nombre))].sort();
  log(`RESUMEN (${todosPlatos.length} platos distintos):`);
  log(`${'Plato'.padEnd(45)} ${DIAS.map(d => d.slice(5)).join('  ')}  TOTAL`);
  log('─'.repeat(70));

  const totalesDia = {};
  for (const plato of todosPlatos) {
    const cols = DIAS.map(fecha => {
      const fila = datosPorDia[fecha].find(f => f.producto_nombre === plato);
      const qty  = fila ? Math.round(fila.cantidad_vendida) : 0;
      if (fila) totalesDia[fecha] = (totalesDia[fecha] || 0) + fila.total;
      return String(qty).padStart(5);
    });
    const total = DIAS.reduce((s, fecha) => {
      const fila = datosPorDia[fecha].find(f => f.producto_nombre === plato);
      return s + (fila ? fila.cantidad_vendida : 0);
    }, 0);
    log(`${plato.slice(0,44).padEnd(45)} ${cols.join('  ')}  ${String(Math.round(total)).padStart(5)}`);
  }

  hr();
  const totalGlobal = Object.values(datosPorDia).flat().reduce((s,f)=>s+f.total,0);
  log(`TOTAL VENTAS PLATOS FUERTES: $${totalGlobal.toFixed(2)}`);
  hr();

  if (DRY_RUN) {
    log('DRY-RUN completado. Para aplicar: node import_ventas_guirola_abril.js --apply');
    return;
  }

  log('Conectando PostgreSQL...');
  const pool = new Pool(pgConfig);
  const pg   = await pool.connect();
  log('Conexión OK');

  for (const fecha of DIAS) {
    await insertarDia(pg, fecha, datosPorDia[fecha]);
  }

  pg.release();
  await pool.end();
  hr();
  log('Proceso completado.');
}

main().catch(e => { console.error('\nERROR FATAL:', e.message); process.exit(1); });
