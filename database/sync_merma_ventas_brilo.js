/**
 * Sync ventas draft desde Brilo SQL Server hacia merma_ventas_brilo
 * Uso: node database/sync_merma_ventas_brilo.js [fecha] [suc_brilo_id]
 * Ejemplo: node database/sync_merma_ventas_brilo.js 2026-10-14 11
 */

require('dotenv').config();
const sql = require('mssql');
const { Client } = require('pg');

const FECHA         = process.argv[2] ?? new Date().toISOString().slice(0, 10);
const SUC_BRILO_ID  = parseInt(process.argv[3] ?? '11');  // 11 = Santa Elena

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

const suc = SUCURSAL_MAP[SUC_BRILO_ID];
if (!suc) { console.error(`suc_brilo_id=${SUC_BRILO_ID} no reconocida`); process.exit(1); }

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
};

async function main() {
  console.log(`\n=== Sync Merma Ventas Brilo ===`);
  console.log(`Fecha: ${FECHA}  |  Sucursal: ${suc.nombre} (brilo=${SUC_BRILO_ID}, pg=${suc.pg_id})\n`);

  // ── 1. Conectar Brilo SQL Server ────────────────────────────────────────────
  console.log('Conectando a Brilo SQL Server...');
  const pool = await sql.connect(cfgMssql);
  console.log('OK\n');

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
      AND MCT.sucIdOrigenSync = ${SUC_BRILO_ID}
      AND CPR.cprNombre = 'Cerveza Draft'
      AND CAST(MCT.mctrstFecHoraCerrada AT TIME ZONE 'UTC'
               AT TIME ZONE 'Central America Standard Time' AS DATE) = '${FECHA}'
    GROUP BY PRO.proNombre, CPR.cprNombre
    ORDER BY SUM(DET.dctrstCantidad * DET.dctrstPrecio) DESC
  `);

  await pool.close();

  if (!result.recordset.length) {
    console.log('Sin ventas Draft en Brilo para esa fecha/sucursal.');
    return;
  }

  console.log(`Ventas Draft encontradas en Brilo (${result.recordset.length} productos):\n`);
  console.log('Producto'.padEnd(35) + 'Cantidad'.padStart(10) + 'Total $'.padStart(12));
  console.log('─'.repeat(57));
  for (const r of result.recordset) {
    console.log(r.producto_nombre.padEnd(35) + String(r.cantidad).padStart(10) + `$${parseFloat(r.total).toFixed(2)}`.padStart(12));
  }

  // ── 2. Conectar Postgres ──────────────────────────────────────────────────
  const pg = new Client(cfgPg);
  await pg.connect();

  // Cargar catálogos locales
  const cervezasRes  = await pg.query('SELECT id, nombre FROM merma_cervezas WHERE estado=\'activo\'');
  const presRes      = await pg.query('SELECT id, presentacion, oz_efectivas FROM merma_presentaciones WHERE activa=true');
  const invRes       = await pg.query(
    "SELECT id FROM merma_inventarios WHERE fecha=$1 AND sucursal_id=$2 AND estado='borrador' LIMIT 1",
    [FECHA, suc.pg_id]
  );

  const cervezas     = cervezasRes.rows;
  const presentaciones = presRes.rows;
  const invId        = invRes.rows[0]?.id;

  if (!invId) {
    console.log(`\nNo hay inventario borrador en pg para ${suc.nombre} / ${FECHA}. Crea el borrador primero en la app.`);
    await pg.end();
    return;
  }

  console.log(`\nInventario pg id=${invId}`);

  // ── 3. Parsear producto_nombre → (cerveza, presentacion) ──────────────────
  // Nombre Brilo ejemplo: "WAPA 16oz", "Roja 23 Oz", "Nacional 1 Lt"
  // Lógica: busca la cerveza cuyo nombre esté contenido en el nombre del producto
  let insertados = 0, sinMapeo = 0;
  console.log('\nMapeando e insertando...\n');

  for (const row of result.recordset) {
    const nombre = row.producto_nombre;

    const cerveza = cervezas.find(c => nombre.toLowerCase().includes(c.nombre.toLowerCase()));
    if (!cerveza) {
      console.log(`  [SIN CERVEZA] "${nombre}" — no coincide con ningún nombre en merma_cervezas`);
      sinMapeo++;
      continue;
    }

    // Extraer presentacion: último token numérico + unidad
    // Ej: "WAPA 16oz" → "16 Oz", "Nacional 1 Lt" → "1 Lt"
    const matchOz = nombre.match(/(\d+(?:\.\d+)?)\s*oz/i);
    const matchLt = nombre.match(/(\d+(?:\.\d+)?)\s*l(?:t|ts?)?/i);
    let presLabel = null;
    if (matchOz) presLabel = `${matchOz[1]} Oz`;
    else if (matchLt) presLabel = parseFloat(matchLt[1]) === 1 ? '1 Lt' : `${parseFloat(matchLt[1])} Lts`;

    const pres = presentaciones.find(p => p.presentacion.toLowerCase() === (presLabel ?? '').toLowerCase());

    const ozEfectivasPorUnidad = pres ? parseFloat(pres.oz_efectivas) : null;
    const ozTotal = ozEfectivasPorUnidad !== null
      ? ozEfectivasPorUnidad * row.cantidad
      : 0;

    if (!pres) {
      console.log(`  [SIN PRESENTACION] "${nombre}" → cerveza="${cerveza.nombre}", presentacion="${presLabel}" no mapeada → oz_efectivas=0`);
    } else {
      console.log(`  ✓ "${nombre}" → ${cerveza.nombre} / ${pres.presentacion} × ${row.cantidad} = ${ozTotal.toFixed(2)} oz fl`);
    }

    await pg.query(`
      INSERT INTO merma_ventas_brilo
        (inventario_id, cerveza_id, presentacion_id, presentacion_brilo, cerveza_brilo,
         cantidad, oz_efectivas, fecha, suc_id_brilo, synced_at)
      VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9, NOW())
      ON CONFLICT (fecha, suc_id_brilo, presentacion_brilo, cerveza_brilo) DO UPDATE
        SET cantidad=$6, oz_efectivas=$7, inventario_id=$1, synced_at=NOW()
    `, [invId, cerveza.id, pres?.id ?? null, presLabel ?? nombre, nombre,
        row.cantidad, ozTotal, FECHA, SUC_BRILO_ID]);

    insertados++;
  }

  await pg.end();

  console.log(`\nResumen: ${insertados} insertados/actualizados, ${sinMapeo} sin mapeo de cerveza.`);
  if (sinMapeo > 0) {
    console.log('→ Los sin mapeo necesitan que el nombre en merma_cervezas coincida con el nombre del producto Brilo.');
  }
}

main().catch(e => { console.error(e.message); process.exit(1); });
