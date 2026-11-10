/**
 * sync_merma_ventas_all.js
 * Sincroniza ventas Draft de Brilo para TODAS las sucursales.
 *
 * Uso: node database/sync_merma_ventas_all.js [--dias N]
 *   N = cuántos días hacia atrás sincronizar (default 1 = solo ayer)
 *   Las ventas de Brilo cierran al día siguiente, por eso nunca se sincroniza "hoy".
 */
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const sql = require('mssql');
const { Client } = require('pg');

const diasIdx = process.argv.findIndex(a => a === '--dias');
const diasEq  = process.argv.find(a => a.startsWith('--dias='));
const DIAS    = diasEq
  ? parseInt(diasEq.split('=')[1])
  : diasIdx >= 0 ? parseInt(process.argv[diasIdx + 1] ?? '1') : 1;

// Brilo sucIdOrigenSync → { pg_id, nombre }
const SUCURSAL_MAP = {
   3: { pg_id:  1, nombre: 'ZONA ROSA'    },
   6: { pg_id:  3, nombre: 'LA LIBERTAD'  },
   7: { pg_id:  4, nombre: 'AEROPUERTO 1' },
   8: { pg_id:  5, nombre: 'AEROPUERTO 2' },
  10: { pg_id:  7, nombre: 'PASEO VENECIA'},
  11: { pg_id:  8, nombre: 'SANTA ELENA'  },
  12: { pg_id:  9, nombre: 'HUIZUCAR'     },
  13: { pg_id: 10, nombre: 'OPICO'        },
  19: { pg_id: 11, nombre: 'CASA GUIROLA' },
};

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

function fechaOffset(dias) {
  const d = new Date();
  d.setDate(d.getDate() - dias);
  return d.toISOString().slice(0, 10);
}

async function syncUno(pool, pg, sucBriloId, suc, fecha, cervezas, presentaciones) {
  const result = await pool.request().query(`
    SELECT
      LTRIM(RTRIM(PRO.proNombre))                       AS producto_nombre,
      SUM(DET.dctrstCantidad)                           AS cantidad
    FROM olRestaurante.dbo.maeCuentasRst MCT WITH (NOLOCK)
    INNER JOIN olRestaurante.dbo.detCuentasRst  DET WITH (NOLOCK) ON DET.mctrstId = MCT.mctrstId
    INNER JOIN olComun.dbo.Productos            PRO WITH (NOLOCK) ON PRO.proId    = DET.proId
    LEFT  JOIN olComun.dbo.CategoriasProductos  CPR WITH (NOLOCK) ON CPR.cprId   = PRO.cprId
    WHERE MCT.mctrstEliminado = 0
      AND DET.dctrstEliminado = 0
      AND DET.dctrstIdModificadorDe IS NULL
      AND MCT.sucIdOrigenSync = ${sucBriloId}
      AND CPR.cprNombre = 'Cerveza Draft'
      AND CAST(MCT.mctrstFecHoraCerrada AT TIME ZONE 'UTC'
               AT TIME ZONE 'Central America Standard Time' AS DATE) = '${fecha}'
    GROUP BY PRO.proNombre
  `);

  if (!result.recordset.length) {
    console.log(`    [${suc.nombre}] Sin ventas Draft`);
    return 0;
  }

  const invRes = await pg.query(
    `SELECT id, estado FROM merma_inventarios
     WHERE fecha=$1 AND sucursal_id=$2
       AND estado IN ('borrador','enviado','aprobado')
     ORDER BY CASE estado WHEN 'borrador' THEN 0 WHEN 'enviado' THEN 1 ELSE 2 END
     LIMIT 1`,
    [fecha, suc.pg_id]
  );
  const invId = invRes.rows[0]?.id;

  if (!invId) {
    console.log(`    [${suc.nombre}] Sin inventario en pg para ${fecha} — ventas no vinculadas`);
    return 0;
  }

  let insertados = 0, sinMapeo = 0;

  for (const row of result.recordset) {
    const nombreNorm = row.producto_nombre
      .replace(/(\d)(onz|oz)/gi, '$1 $2')
      .replace(/onz/gi, 'oz');

    const cerveza = cervezas.find(c =>
      nombreNorm.toLowerCase().includes(c.nombre.toLowerCase())
    );
    if (!cerveza) { sinMapeo++; continue; }

    const matchOz = nombreNorm.match(/(\d+(?:\.\d+)?)\s*oz/i);
    const matchLt = nombreNorm.match(/(\d+(?:\.\d+)?)\s*l(?:t|ts?)?/i);
    let presLabel = null;
    if (matchOz)      presLabel = `${matchOz[1]} Oz`;
    else if (matchLt) presLabel = parseFloat(matchLt[1]) === 1 ? '1 Lt' : `${parseFloat(matchLt[1])} Lts`;

    const pres    = presentaciones.find(p =>
      p.presentacion.toLowerCase() === (presLabel ?? '').toLowerCase()
    );
    const ozTotal = pres ? parseFloat(pres.oz_efectivas) * row.cantidad : 0;

    await pg.query(
      `INSERT INTO merma_ventas_brilo
         (inventario_id, cerveza_id, presentacion_id, presentacion_brilo, cerveza_brilo,
          cantidad, oz_efectivas, fecha, suc_id_brilo, synced_at)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9, NOW())
       ON CONFLICT (fecha, suc_id_brilo, presentacion_brilo, cerveza_brilo) DO UPDATE
         SET cantidad=$6, oz_efectivas=$7, inventario_id=$1, synced_at=NOW()`,
      [invId, cerveza.id, pres?.id ?? null, presLabel ?? row.producto_nombre,
       row.producto_nombre, row.cantidad, ozTotal, fecha, sucBriloId]
    );
    insertados++;
  }

  const sinMapeoMsg = sinMapeo > 0 ? `, ${sinMapeo} sin mapeo` : '';
  console.log(`    [${suc.nombre}] inv=${invId} (${invRes.rows[0].estado}) → ${insertados} registros${sinMapeoMsg}`);
  return insertados;
}

async function main() {
  const fechas = [];
  for (let i = DIAS; i >= 1; i--) fechas.push(fechaOffset(i));

  console.log(`\n=== Sync Merma Ventas — todas las sucursales ===`);
  console.log(`Fechas a sincronizar: ${fechas.join(', ')}`);
  console.log(`Sucursales: ${Object.values(SUCURSAL_MAP).map(s => s.nombre).join(', ')}\n`);

  const pool = await sql.connect(cfgMssql);
  console.log('Brilo SQL Server OK');

  const pg = new Client(cfgPg);
  await pg.connect();
  console.log('PostgreSQL OK\n');

  const cervezas      = (await pg.query("SELECT id, nombre FROM merma_cervezas WHERE estado='activo'")).rows;
  const presentaciones = (await pg.query("SELECT id, presentacion, oz_efectivas FROM merma_presentaciones WHERE activa=true")).rows;

  let total = 0;

  for (const fecha of fechas) {
    console.log(`\n  Fecha: ${fecha}`);
    for (const [briloId, suc] of Object.entries(SUCURSAL_MAP)) {
      total += await syncUno(pool, pg, briloId, suc, fecha, cervezas, presentaciones);
    }
  }

  await pool.close();
  await pg.end();

  console.log(`\n=== Total insertados/actualizados: ${total} ===`);
}

main().catch(e => { console.error(e.message); process.exit(1); });
