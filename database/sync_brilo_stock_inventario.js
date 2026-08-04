/**
 * sync_brilo_stock_inventario.js
 * Sincroniza saldos de Brilo (SQL Server) → inventarios.brilo_stock (compras_db)
 * y registra snapshot diario en prod_seg_historico para los productos en seguimiento.
 *
 * Uso:
 *   node database/sync_brilo_stock_inventario.js              ← todas las sucursales
 *   node database/sync_brilo_stock_inventario.js <sucursal_id> ← solo esa sucursal
 *   node database/sync_brilo_stock_inventario.js --dry-run    ← simula sin escribir en Brilo
 *   node database/sync_brilo_stock_inventario.js 3 2026-06-28 ← La Libertad, histórico
 */

require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const sql  = require('mssql');
const { Pool } = require('pg');

// ── Conexiones ────────────────────────────────────────────────────────────────
const BRILO_CFG = {
  user:     process.env.DB_USERNAME_ORIGEN,
  password: process.env.DB_PASSWORD_ORIGEN,
  server:   process.env.DB_HOST_ORIGEN,
  port:     Number(process.env.DB_PORT_ORIGEN) || 2033,
  database: 'olInventario',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 30000 },
  requestTimeout: 300000,
};

const PG_CFG = {
  host:     process.env.DB_HOST_COMPRAS,
  port:     Number(process.env.DB_PORT_COMPRAS) || 5432,
  database: process.env.DB_DATABASE_COMPRAS,
  user:     process.env.DB_USERNAME_COMPRAS,
  password: process.env.DB_PASSWORD_COMPRAS,
  ssl: { rejectUnauthorized: false },
};

// ── Mapa sucursal_id → ubiId en Brilo ────────────────────────────────────────
const SUCURSAL_UBI_MAP = {
   1: { ubiId: 37, nombre: 'REST. ZONA ROSA'      },
   3: { ubiId: 48, nombre: 'REST. LA LIBERTAD'    },
   4: { ubiId: 51, nombre: 'REST. AEROPUERTO #1'  },
   5: { ubiId: 52, nombre: 'REST. AEROPUERTO #2'  },
   7: { ubiId: 57, nombre: 'REST. PASEO VENECIA'  },
   8: { ubiId: 58, nombre: 'REST. SANTA ELENA'    },
   9: { ubiId: 65, nombre: 'REST. HUIZUCAR'       },
  10: { ubiId: 69, nombre: 'REST. OPICO'          },
  11: { ubiId: 77, nombre: 'REST. CASA GUIROLA'   },
  16: { ubiId: 76, nombre: 'REST. MALCRIADAS AE2' },
};

// ── Helpers ───────────────────────────────────────────────────────────────────
function fechaSQL(d) {
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${mm}/${dd}/${d.getFullYear()}`;
}

function buildKardexQuery(ubiId, fecha) {
  const f = fechaSQL(fecha);
  return `
    DECLARE @Fecha_Desde1       AS DATETIME = '${f}'
    DECLARE @Fecha_Hasta1       AS DATETIME = '${f}'
    SELECT @Fecha_Hasta1 = DATEADD(S, -1, DATEADD(DAY, 1, @Fecha_Hasta1))

    DECLARE @Ubicacion1                        AS INT = ${ubiId}
    DECLARE @Categoria_Producto1               AS INT = NULL
    DECLARE @Categoria_Secundaria_Producto1    AS INT = NULL
    DECLARE @Marca1                            AS INT = NULL
    DECLARE @Incluir_Activos1                  AS BIT = 0
    DECLARE @Incluir_Producto_Saldo_Final_A_Cero1 AS BIT = 1
    DECLARE @Incluir_Productos_Con_Movimiento1 AS BIT = 0
    DECLARE @cfgKardexMuestraAnulaciones       AS BIT = 0

    SELECT TOP 1 @cfgKardexMuestraAnulaciones = cfgKardexMuestraAnulaciones
    FROM olComun.dbo.ConfigBase WITH (NOLOCK)

    CREATE TABLE #ChildUBIs (ubiId INT NOT NULL PRIMARY KEY CLUSTERED)
    IF @Ubicacion1 IS NOT NULL
        INSERT INTO #ChildUBIs SELECT ubiId FROM olInventario.dbo.GetChildUBIs(@Ubicacion1)

    CREATE TABLE #ChildCPRs (cprId INT NOT NULL PRIMARY KEY CLUSTERED)

    CREATE TABLE #MaeMovi (
        mmoId INT NOT NULL PRIMARY KEY CLUSTERED, tmoSigno SMALLINT NOT NULL,
        mmoAnulado BIT NOT NULL, mmoFecha DATETIME NOT NULL,
        mmoFecHoraAnulado DATETIME NULL, mmoFechaEnRango BIT NOT NULL
    )
    INSERT INTO #MaeMovi
    SELECT MMO.mmoId, TMO.tmoSigno, MMO.mmoAnulado, MMO.mmoFecha, MMO.mmoFecHoraAnulado,
        CASE WHEN MMO.mmoFecha BETWEEN @Fecha_Desde1 AND @Fecha_Hasta1 THEN 1 ELSE 0 END
    FROM olInventario.dbo.maeMovi MMO WITH (NOLOCK)
        INNER JOIN olInventario.dbo.TiposMovi TMO WITH (NOLOCK) ON MMO.tmoId = TMO.tmoId
    WHERE MMO.mmoPosteado = 1
      AND ((MMO.mmoAnulado = 0 AND MMO.mmoFecha BETWEEN @Fecha_Desde1 AND @Fecha_Hasta1)
        OR (@cfgKardexMuestraAnulaciones = 1 AND MMO.mmoAnulado = 1 AND (
            MMO.mmoFecha BETWEEN @Fecha_Desde1 AND @Fecha_Hasta1
            OR (MMO.mmoFecHoraAnulado IS NOT NULL AND MMO.mmoFecHoraAnulado BETWEEN @Fecha_Desde1 AND @Fecha_Hasta1))))

    CREATE INDEX IX_tmp_MaeMovi ON #MaeMovi (mmoAnulado, mmoFechaEnRango)

    CREATE TABLE #HistFechas (proId INT NOT NULL PRIMARY KEY CLUSTERED, UltFecHistSaldo DATETIME NOT NULL, UltFecHistCosto DATETIME NOT NULL)
    INSERT INTO #HistFechas (proId, UltFecHistSaldo, UltFecHistCosto)
    SELECT PRO.proId, ISNULL(MAX(SALH.salhFecha), '1990/01/01'), ISNULL(MAX(COSH.coshFecha), '1990/01/01')
    FROM olComun.dbo.Productos PRO WITH (NOLOCK)
        INNER JOIN olComun.dbo.TiposProductos TPR WITH (NOLOCK) ON TPR.tproId = PRO.proTipo AND TPR.tproEsInventariado = 1
        LEFT JOIN olInventario.dbo.SaldosHistoricos SALH WITH (NOLOCK) ON SALH.proId = PRO.proId AND SALH.salhFecha <= @Fecha_Hasta1
        LEFT JOIN olInventario.dbo.CostosHistoricos COSH WITH (NOLOCK) ON COSH.proId = PRO.proId AND COSH.coshFecha <= @Fecha_Hasta1
    GROUP BY PRO.proId

    CREATE TABLE #SaldoCosto (proId INT NOT NULL, mloId INT NULL, mloNumero VARCHAR(50) NULL, mloFechaVence DATETIME NULL, Saldo DECIMAL(18,6) NOT NULL, CostoProm DECIMAL(18,6) NOT NULL, ubiId INT NULL)
    INSERT INTO #SaldoCosto (proId, mloId, mloNumero, mloFechaVence, Saldo, CostoProm, ubiId)
    SELECT DT.proId, DT.mloId, MLO.mloNumero, MLO.mloFechaVence, SUM(DT.Saldo), 0, DT.ubiId
    FROM (
        SELECT SALH.proId, SALH.ubiId, SALH.mloId, SALH.salhSaldo AS Saldo
        FROM olInventario.dbo.SaldosHistoricos SALH WITH (NOLOCK)
            INNER JOIN #HistFechas H ON H.proId = SALH.proId AND SALH.salhFecha = H.UltFecHistSaldo
        WHERE H.UltFecHistSaldo > '1990/01/01'
          AND (@Ubicacion1 IS NULL OR EXISTS (SELECT 1 FROM #ChildUBIs cu WHERE cu.ubiId = SALH.ubiId))
        UNION ALL
        SELECT DMO.proId, DMO.ubiId, DMO.mloId, SUM(DMO.dmoCantidad * TMO.tmoSigno)
        FROM olInventario.dbo.maeMovi MMO WITH (NOLOCK)
            INNER JOIN olInventario.dbo.detMovi DMO WITH (NOLOCK) ON DMO.mmoId = MMO.mmoId
            INNER JOIN olInventario.dbo.TiposMovi TMO WITH (NOLOCK) ON TMO.tmoId = MMO.tmoId
            INNER JOIN #HistFechas H ON H.proId = DMO.proId
        WHERE MMO.mmoPosteado = 1 AND MMO.mmoAnulado = 0
          AND MMO.mmoFecha > H.UltFecHistSaldo AND MMO.mmoFecha <= @Fecha_Hasta1
          AND (@Ubicacion1 IS NULL OR EXISTS (SELECT 1 FROM #ChildUBIs cu WHERE cu.ubiId = DMO.ubiId))
        GROUP BY DMO.proId, DMO.ubiId, DMO.mloId
    ) DT LEFT JOIN olInventario.dbo.maeLotes MLO WITH (NOLOCK) ON MLO.mloId = DT.mloId
    GROUP BY DT.proId, DT.ubiId, DT.mloId, MLO.mloNumero, MLO.mloFechaVence
    HAVING SUM(DT.Saldo) <> 0

    CREATE INDEX IX_tmp_SaldoCosto ON #SaldoCosto (proId)

    SELECT PRO.proCodigo AS Codigo, SUM(ISNULL(QRY.Saldo, 0)) AS SaldoFinal
    FROM olComun.dbo.Productos PRO WITH (NOLOCK)
        LEFT JOIN (
            SELECT proId, SUM(ISNULL(Saldo,0)) AS Saldo
            FROM (
                SELECT DMO.proId, SUM(CASE WHEN MMO.tmoSigno=1 THEN DMO.dmoCantidad ELSE 0 END) AS dmoEntradas,
                    SUM(CASE WHEN MMO.tmoSigno=-1 THEN DMO.dmoCantidad ELSE 0 END) AS dmoSalidas,
                    0 AS dmoEntradasAdic, 0 AS dmoSalidasAdic, 0 AS Saldo
                FROM olInventario.dbo.detMovi DMO WITH (NOLOCK)
                    INNER JOIN #MaeMovi MMO ON DMO.mmoId=MMO.mmoId AND (MMO.mmoAnulado=0 OR @cfgKardexMuestraAnulaciones=1) AND MMO.mmoFechaEnRango=1
                WHERE (@Ubicacion1 IS NULL OR EXISTS (SELECT 1 FROM #ChildUBIs cu WHERE cu.ubiId=DMO.ubiId))
                GROUP BY DMO.proId
                UNION ALL
                SELECT SC.proId, 0, 0, 0, 0, SUM(SC.Saldo)
                FROM #SaldoCosto SC GROUP BY SC.proId
            ) DT GROUP BY proId
        ) QRY ON QRY.proId = PRO.proId
        LEFT JOIN olComun.dbo.CategoriasProductos CPR WITH (NOLOCK) ON PRO.cprId = CPR.cprId
    WHERE ((@Incluir_Activos1=0 AND PRO.proTipo=0) OR (@Incluir_Activos1=1 AND PRO.proTipo IN(0,2,4,5)))
      AND (@Categoria_Producto1 IS NULL OR PRO.cprId IN (SELECT cprId FROM #ChildCPRs))
      AND (ISNULL(PRO.marId,0)=@Marca1 OR @Marca1 IS NULL)
    GROUP BY PRO.proCodigo
    HAVING SUM(ISNULL(QRY.Saldo,0)) > 0
    ORDER BY PRO.proCodigo

    DROP TABLE #ChildUBIs; DROP TABLE #ChildCPRs; DROP TABLE #MaeMovi; DROP TABLE #HistFechas; DROP TABLE #SaldoCosto
  `;
}

// ── Insertar snapshot en prod_seg_historico ───────────────────────────────────
async function guardarHistorico(pg, sucursalId, fecha, briloMap, isDryRun) {
  const fechaStr = fecha.toISOString().split('T')[0];
  const syncAt   = new Date().toISOString();

  // Prod_seg products con su brilo_stock y último conteo físico del mes
  const { rows: prodSeg } = await pg.query(`
    SELECT i.producto_id, p.codigo, p.nombre, i.brilo_stock,
           (
             SELECT (m.detalle->>'total_contado')::numeric
             FROM movimientos_inventario m
             WHERE m.sucursal_id = i.sucursal_id
               AND m.producto_id = i.producto_id
               AND m.tipo = 'conteo_fisico'
               AND m.fecha = $2
             ORDER BY m.created_at DESC
             LIMIT 1
           ) AS conteo_fisico
    FROM inventarios i
    JOIN productos p ON p.id = i.producto_id
    WHERE i.sucursal_id = $1 AND i.prod_seg = true AND i.activo = true
  `, [sucursalId, fechaStr]);

  if (prodSeg.length === 0) {
    console.log(`  [historico] Sin productos prod_seg para sucursal ${sucursalId}`);
    return;
  }

  console.log(`\n  ┌─ Histórico prod_seg (${prodSeg.length} productos):`);
  let ok = 0;
  for (const p of prodSeg) {
    const rawStock = briloMap[p.codigo?.trim()] ?? p.brilo_stock ?? null;
    const stock    = rawStock !== null ? parseFloat(rawStock) : null;
    const conteo  = p.conteo_fisico !== null ? parseFloat(p.conteo_fisico) : null;
    const diff    = (stock !== null && conteo !== null) ? parseFloat((conteo - stock).toFixed(6)) : null;
    const seg     = conteo !== null ? (diff >= 0 ? '✅' : '⚠️ ') : '—';

    console.log(`  │ ${seg} ${p.codigo.padEnd(12)} ${p.nombre.substring(0,30).padEnd(30)}  brilo=${stock?.toFixed(2) ?? 'n/a'}  conteo=${conteo?.toFixed(2) ?? 'n/a'}  diff=${diff?.toFixed(2) ?? 'n/a'}`);

    if (!isDryRun) {
      await pg.query(`
        INSERT INTO prod_seg_historico (sucursal_id, producto_id, fecha, sync_at, brilo_stock, conteo_fisico, diferencia, created_at, updated_at)
        VALUES ($1, $2, $3, $4, $5, $6, $7, NOW(), NOW())
        ON CONFLICT (sucursal_id, producto_id, fecha)
        DO UPDATE SET brilo_stock=EXCLUDED.brilo_stock, conteo_fisico=EXCLUDED.conteo_fisico,
                      diferencia=EXCLUDED.diferencia, sync_at=EXCLUDED.sync_at, updated_at=NOW()
      `, [sucursalId, p.producto_id, fechaStr, syncAt, stock, conteo, diff]);
      ok++;
    }
  }
  console.log(`  └─ ${isDryRun ? '[DRY RUN — no se escribió]' : `${ok} filas upserted en prod_seg_historico`}`);
}

// ── Sync de una sucursal ──────────────────────────────────────────────────────
async function syncSucursal(pg, sucursalId, fechaKardex, isDryRun) {
  const mapeo = SUCURSAL_UBI_MAP[sucursalId];
  if (!mapeo) {
    console.log(`\n⚠️  sucursal_id=${sucursalId} no tiene mapeo Brilo — omitida`);
    return { actualizados: 0, enCero: 0, insertados: 0 };
  }
  const { ubiId, nombre } = mapeo;

  console.log(`\n${'═'.repeat(64)}`);
  console.log(`▸ ${nombre} (suc_id=${sucursalId}, ubiId=${ubiId}, fecha=${fechaSQL(fechaKardex)})`);
  console.log(`${'═'.repeat(64)}`);

  // Kardex Brilo
  const query  = buildKardexQuery(ubiId, fechaKardex);
  const result = await sql.connect(BRILO_CFG).then(pool => pool.request().query(query));
  await sql.close();

  const briloMap = {};
  result.recordset.forEach(r => { briloMap[r.Codigo.trim()] = parseFloat(r.SaldoFinal); });
  console.log(`  Saldos de Brilo: ${result.recordset.length} productos con saldo > 0`);

  // Inventarios existentes para esta sucursal
  const { rows: invItems } = await pg.query(`
    SELECT i.id, i.producto_id, p.codigo
    FROM inventarios i JOIN productos p ON p.id = i.producto_id
    WHERE i.sucursal_id = $1
  `, [sucursalId]);

  let actualizados = 0, enCero = 0, insertados = 0;

  if (!isDryRun) {
    for (const item of invItems) {
      const codigo = item.codigo?.trim();
      if (!codigo) continue;
      const stock = briloMap[codigo];
      await pg.query(
        `UPDATE inventarios SET brilo_stock=$1, brilo_sync_at=NOW() WHERE id=$2`,
        [stock !== undefined ? stock : 0, item.id]
      );
      stock !== undefined ? actualizados++ : enCero++;
    }

    // Insertar nuevos (productos en Brilo sin registro en inventarios)
    const invCodigos    = new Set(invItems.map(i => i.codigo?.trim()));
    const codigosNuevos = Object.keys(briloMap).filter(c => !invCodigos.has(c));
    if (codigosNuevos.length > 0) {
      const { rows: prodsNuevos } = await pg.query(
        `SELECT id, codigo FROM productos WHERE codigo = ANY($1)`, [codigosNuevos]
      );
      for (const p of prodsNuevos) {
        const stock = briloMap[p.codigo.trim()];
        if (!stock) continue;
        await pg.query(`
          INSERT INTO inventarios (producto_id, sucursal_id, brilo_stock, brilo_sync_at, unidad, fecha_conteo, created_at, updated_at)
          SELECT $1, $2, $3, NOW(), COALESCE(p.unidad,'u'), NOW(), NOW(), NOW()
          FROM productos p WHERE p.id = $1
          ON CONFLICT (producto_id, sucursal_id) DO UPDATE SET brilo_stock=EXCLUDED.brilo_stock, brilo_sync_at=NOW()
        `, [p.id, sucursalId, stock]);
        insertados++;
      }
    }
    console.log(`  Actualizados: ${actualizados}  En cero: ${enCero}  Nuevos: ${insertados}`);
  } else {
    console.log(`  [DRY RUN] Se actualizarían ${invItems.length} registros de inventarios`);
  }

  // Guardar histórico prod_seg
  await guardarHistorico(pg, sucursalId, fechaKardex, briloMap, isDryRun);

  return { actualizados, enCero, insertados };
}

// ── Main ──────────────────────────────────────────────────────────────────────
(async () => {
  const args      = process.argv.slice(2).filter(a => a !== '--dry-run');
  const isDryRun  = process.argv.includes('--dry-run');
  const sucursalArg = args[0] && !isNaN(args[0]) ? parseInt(args[0], 10) : null;
  const fechaArg    = args.find(a => /^\d{4}-\d{2}-\d{2}$/.test(a)) || null;

  const fechaKardex = fechaArg ? new Date(fechaArg + 'T12:00:00') : new Date();
  const sucursalIds = sucursalArg
    ? [sucursalArg]
    : Object.keys(SUCURSAL_UBI_MAP).map(Number);

  console.log(`\n${'█'.repeat(64)}`);
  console.log(`  SYNC BRILO → compras_db${isDryRun ? '  [DRY RUN]' : ''}`);
  console.log(`  Fecha kardex : ${fechaSQL(fechaKardex)}`);
  console.log(`  Sucursales   : ${sucursalIds.join(', ')}`);
  console.log(`  Timestamp    : ${new Date().toISOString()}`);
  console.log(`${'█'.repeat(64)}`);

  if (isDryRun) {
    console.log('\n⚠️  MODO DRY RUN — no se escribirá nada en la base de datos\n');
  }

  const pg = new Pool(PG_CFG);
  await pg.query('SELECT 1');
  console.log('Conectado a compras_db (PostgreSQL) ✓');

  let totalAct = 0, totalCero = 0, totalIns = 0, errores = [];

  for (const sucursalId of sucursalIds) {
    try {
      const r = await syncSucursal(pg, sucursalId, fechaKardex, isDryRun);
      totalAct  += r.actualizados;
      totalCero += r.enCero;
      totalIns  += r.insertados;
    } catch (e) {
      console.error(`\n❌ Error en sucursal ${sucursalId}: ${e.message}`);
      errores.push({ sucursalId, error: e.message });
      try { await sql.close(); } catch (_) {}
    }
  }

  console.log(`\n${'═'.repeat(64)}`);
  console.log(`  RESUMEN FINAL${isDryRun ? ' [DRY RUN]' : ''}`);
  console.log(`  Actualizados : ${totalAct}`);
  console.log(`  En cero      : ${totalCero}`);
  console.log(`  Insertados   : ${totalIns}`);
  console.log(`  Errores      : ${errores.length}`);
  if (errores.length) errores.forEach(e => console.log(`    - suc_id=${e.sucursalId}: ${e.error}`));
  console.log(`  Finalizado   : ${new Date().toISOString()}`);
  console.log(`${'═'.repeat(64)}\n`);

  await pg.end();
})().catch(e => { console.error('Error fatal:', e.message); process.exit(1); });
