/**
 * sync_brilo_stock_inventario.js
 * Sincroniza saldos de Brilo (SQL Server) → columna brilo_stock en compras_db.inventarios
 * usando el mismo método kardex que el reporte oficial de Brilo.
 *
 * Uso:
 *   node database/sync_brilo_stock_inventario.js <sucursal_id> [fecha YYYY-MM-DD]
 *
 * Ejemplos:
 *   node database/sync_brilo_stock_inventario.js 3              ← La Libertad, hoy
 *   node database/sync_brilo_stock_inventario.js 3 2026-06-28   ← La Libertad, histórico
 *   node database/sync_brilo_stock_inventario.js 11             ← Huizucar, hoy
 */

require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const sql  = require('mssql');
const { Pool } = require('pg');

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

// Mapa sucursal_id (compras_db) → { ubiId en Brilo, nombre }
// Para agregar una sucursal nueva: añadir aquí su sucursal_id y el ubiId de BRILO
const SUCURSAL_UBI_MAP = {
   3: { ubiId: 48, nombre: 'REST. LA LIBERTAD'      },
  11: { ubiId: 65, nombre: 'REST. HUIZUCAR'          },
  // Disponibles en BRILO (agregar sucursal_id cuando se activen en compras_db):
  // ?: { ubiId: 37, nombre: 'REST. ZONA ROSA'        },
  // ?: { ubiId: 38, nombre: 'REST. SANTA ROSA'       },
  // ?: { ubiId: 51, nombre: 'REST. AEROPUERTO #1'    },
  // ?: { ubiId: 52, nombre: 'REST. AEROPUERTO #2'    },
  // ?: { ubiId: 56, nombre: 'REST. SAN MIGUEL'       },
  // ?: { ubiId: 57, nombre: 'REST. PASEO VENECIA'    },
  // ?: { ubiId: 58, nombre: 'REST. SANTA ELENA'      },
  // ?: { ubiId: 69, nombre: 'REST. OPICO'            },
  // ?: { ubiId: 76, nombre: 'REST. MALCRIADAS AE2'   },
  // ?: { ubiId: 77, nombre: 'REST. CASA GUIROLA'     },
  // ?: { ubiId: 78, nombre: 'REST. LAGO COATEPEQUE'  },
  // ?: { ubiId: 79, nombre: 'REST. PUERTA DEL DIABLO'},
};

function fechaSQL(d) {
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  const yyyy = d.getFullYear();
  return `${mm}/${dd}/${yyyy}`;
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

    -- ── Tabla temporal 1: ubicaciones hijas ──────────────────────────────────
    CREATE TABLE #ChildUBIs (ubiId INT NOT NULL PRIMARY KEY CLUSTERED)
    IF @Ubicacion1 IS NOT NULL
        INSERT INTO #ChildUBIs SELECT ubiId FROM olInventario.dbo.GetChildUBIs(@Ubicacion1)

    -- ── Tabla temporal 2: categorías hijas (sin filtro, no se popula) ────────
    CREATE TABLE #ChildCPRs (cprId INT NOT NULL PRIMARY KEY CLUSTERED)

    -- ── Tabla temporal 3: movimientos filtrados por fecha ────────────────────
    CREATE TABLE #MaeMovi (
        mmoId             INT      NOT NULL PRIMARY KEY CLUSTERED,
        tmoSigno          SMALLINT NOT NULL,
        mmoAnulado        BIT      NOT NULL,
        mmoFecha          DATETIME NOT NULL,
        mmoFecHoraAnulado DATETIME NULL,
        mmoFechaEnRango   BIT      NOT NULL
    )

    INSERT INTO #MaeMovi
    SELECT
        MMO.mmoId, TMO.tmoSigno, MMO.mmoAnulado, MMO.mmoFecha, MMO.mmoFecHoraAnulado,
        CASE WHEN MMO.mmoFecha BETWEEN @Fecha_Desde1 AND @Fecha_Hasta1 THEN 1 ELSE 0 END
    FROM olInventario.dbo.maeMovi MMO WITH (NOLOCK)
        INNER JOIN olInventario.dbo.TiposMovi TMO WITH (NOLOCK) ON MMO.tmoId = TMO.tmoId
    WHERE MMO.mmoPosteado = 1
      AND (
        (MMO.mmoAnulado = 0 AND MMO.mmoFecha BETWEEN @Fecha_Desde1 AND @Fecha_Hasta1)
        OR (
            @cfgKardexMuestraAnulaciones = 1 AND MMO.mmoAnulado = 1 AND (
                MMO.mmoFecha BETWEEN @Fecha_Desde1 AND @Fecha_Hasta1
                OR (MMO.mmoFecHoraAnulado IS NOT NULL
                    AND MMO.mmoFecHoraAnulado BETWEEN @Fecha_Desde1 AND @Fecha_Hasta1)
            )
        )
      )

    CREATE INDEX IX_tmp_MaeMovi ON #MaeMovi (mmoAnulado, mmoFechaEnRango)

    -- ── Tabla temporal 4a: última fecha histórica por producto ───────────────
    CREATE TABLE #HistFechas (
        proId          INT      NOT NULL PRIMARY KEY CLUSTERED,
        UltFecHistSaldo DATETIME NOT NULL,
        UltFecHistCosto DATETIME NOT NULL
    )

    INSERT INTO #HistFechas (proId, UltFecHistSaldo, UltFecHistCosto)
    SELECT
        PRO.proId,
        ISNULL(MAX(SALH.salhFecha), '1990/01/01'),
        ISNULL(MAX(COSH.coshFecha), '1990/01/01')
    FROM olComun.dbo.Productos PRO WITH (NOLOCK)
        INNER JOIN olComun.dbo.TiposProductos TPR WITH (NOLOCK)
            ON TPR.tproId = PRO.proTipo AND TPR.tproEsInventariado = 1
        LEFT JOIN olInventario.dbo.SaldosHistoricos SALH WITH (NOLOCK)
            ON SALH.proId = PRO.proId AND SALH.salhFecha <= @Fecha_Hasta1
        LEFT JOIN olInventario.dbo.CostosHistoricos COSH WITH (NOLOCK)
            ON COSH.proId = PRO.proId AND COSH.coshFecha <= @Fecha_Hasta1
    GROUP BY PRO.proId

    -- ── Tabla temporal 4b: saldos al cierre del período ──────────────────────
    CREATE TABLE #SaldoCosto (
        proId         INT           NOT NULL,
        mloId         INT           NULL,
        mloNumero     VARCHAR(50)   NULL,
        mloFechaVence DATETIME      NULL,
        Saldo         DECIMAL(18,6) NOT NULL,
        CostoProm     DECIMAL(18,6) NOT NULL,
        ubiId         INT           NULL
    )

    INSERT INTO #SaldoCosto (proId, mloId, mloNumero, mloFechaVence, Saldo, CostoProm, ubiId)
    SELECT DT.proId, DT.mloId, MLO.mloNumero, MLO.mloFechaVence, SUM(DT.Saldo), 0, DT.ubiId
    FROM (
        -- BASE: saldo del último cierre histórico
        SELECT SALH.proId, SALH.ubiId, SALH.mloId, SALH.salhSaldo AS Saldo
        FROM olInventario.dbo.SaldosHistoricos SALH WITH (NOLOCK)
            INNER JOIN #HistFechas H ON H.proId = SALH.proId AND SALH.salhFecha = H.UltFecHistSaldo
        WHERE H.UltFecHistSaldo > '1990/01/01'
          AND (@Ubicacion1 IS NULL OR EXISTS (SELECT 1 FROM #ChildUBIs cu WHERE cu.ubiId = SALH.ubiId))

        UNION ALL

        -- INCREMENTO: movimientos desde el último cierre hasta hoy
        SELECT DMO.proId, DMO.ubiId, DMO.mloId, SUM(DMO.dmoCantidad * TMO.tmoSigno)
        FROM olInventario.dbo.maeMovi MMO WITH (NOLOCK)
            INNER JOIN olInventario.dbo.detMovi DMO WITH (NOLOCK) ON DMO.mmoId = MMO.mmoId
            INNER JOIN olInventario.dbo.TiposMovi TMO WITH (NOLOCK) ON TMO.tmoId = MMO.tmoId
            INNER JOIN #HistFechas H ON H.proId = DMO.proId
        WHERE MMO.mmoPosteado = 1 AND MMO.mmoAnulado = 0
          AND MMO.mmoFecha > H.UltFecHistSaldo AND MMO.mmoFecha <= @Fecha_Hasta1
          AND (@Ubicacion1 IS NULL OR EXISTS (SELECT 1 FROM #ChildUBIs cu WHERE cu.ubiId = DMO.ubiId))
        GROUP BY DMO.proId, DMO.ubiId, DMO.mloId
    ) DT
        LEFT JOIN olInventario.dbo.maeLotes MLO WITH (NOLOCK) ON MLO.mloId = DT.mloId
    GROUP BY DT.proId, DT.ubiId, DT.mloId, MLO.mloNumero, MLO.mloFechaVence
    HAVING SUM(DT.Saldo) <> 0

    CREATE INDEX IX_tmp_SaldoCosto ON #SaldoCosto (proId)

    -- ── Resultado final: saldo por producto (suma de todos los lotes) ─────────
    SELECT
        PRO.proCodigo                                                           AS Codigo,
        SUM(ISNULL(QRY.Saldo, 0))                                              AS SaldoFinal
    FROM olComun.dbo.Productos PRO WITH (NOLOCK)
        LEFT JOIN (
            SELECT proId,
                   SUM(dmoEntradas)    AS dmoEntradas,
                   SUM(dmoSalidas)     AS dmoSalidas,
                   SUM(dmoEntradasAdic) AS dmoEntradasAdic,
                   SUM(dmoSalidasAdic)  AS dmoSalidasAdic,
                   SUM(ISNULL(Saldo,0)) AS Saldo
            FROM (
                -- Branch 1: movimientos en rango
                SELECT DMO.proId,
                    SUM(CASE WHEN MMO.tmoSigno =  1 THEN DMO.dmoCantidad ELSE 0 END) AS dmoEntradas,
                    SUM(CASE WHEN MMO.tmoSigno = -1 THEN DMO.dmoCantidad ELSE 0 END) AS dmoSalidas,
                    0 AS dmoEntradasAdic, 0 AS dmoSalidasAdic, 0 AS Saldo
                FROM olInventario.dbo.detMovi DMO WITH (NOLOCK)
                    INNER JOIN #MaeMovi MMO ON DMO.mmoId = MMO.mmoId
                        AND (MMO.mmoAnulado = 0 OR @cfgKardexMuestraAnulaciones = 1)
                        AND MMO.mmoFechaEnRango = 1
                WHERE (@Ubicacion1 IS NULL OR EXISTS (SELECT 1 FROM #ChildUBIs cu WHERE cu.ubiId = DMO.ubiId))
                GROUP BY DMO.proId

                UNION ALL

                -- Branch 2: reverso de anulaciones cuya fecha de anulacion cae en rango
                SELECT DMO.proId,
                    SUM(CASE WHEN MMO.tmoSigno =  1 THEN DMO.dmoCantidad * -1 ELSE 0 END),
                    SUM(CASE WHEN MMO.tmoSigno = -1 THEN DMO.dmoCantidad * -1 ELSE 0 END),
                    0, 0, 0
                FROM olInventario.dbo.detMovi DMO WITH (NOLOCK)
                    INNER JOIN #MaeMovi MMO ON DMO.mmoId = MMO.mmoId
                        AND MMO.mmoAnulado = 1
                        AND (
                            (MMO.mmoFecHoraAnulado IS NOT NULL
                             AND MMO.mmoFecHoraAnulado BETWEEN @Fecha_Desde1 AND @Fecha_Hasta1)
                            OR (MMO.mmoFecHoraAnulado IS NULL AND MMO.mmoFechaEnRango = 1)
                        )
                WHERE (@Ubicacion1 IS NULL OR EXISTS (SELECT 1 FROM #ChildUBIs cu WHERE cu.ubiId = DMO.ubiId))
                  AND @cfgKardexMuestraAnulaciones = 1
                GROUP BY DMO.proId

                UNION ALL

                -- Branch 3: anulaciones fuera de rango
                SELECT DMO.proId,
                    SUM(CASE WHEN MMO.tmoSigno =  1 THEN DMO.dmoCantidad * -1 ELSE 0 END),
                    SUM(CASE WHEN MMO.tmoSigno = -1 THEN DMO.dmoCantidad * -1 ELSE 0 END),
                    SUM(CASE WHEN MMO.tmoSigno =  1 THEN DMO.dmoCantidad * -1 ELSE 0 END),
                    SUM(CASE WHEN MMO.tmoSigno = -1 THEN DMO.dmoCantidad * -1 ELSE 0 END),
                    0
                FROM olInventario.dbo.detMovi DMO WITH (NOLOCK)
                    INNER JOIN #MaeMovi MMO ON DMO.mmoId = MMO.mmoId
                        AND MMO.mmoAnulado = 1
                        AND MMO.mmoFecHoraAnulado IS NOT NULL
                        AND MMO.mmoFecHoraAnulado NOT BETWEEN @Fecha_Desde1 AND @Fecha_Hasta1
                        AND MMO.mmoFechaEnRango = 1
                WHERE (@Ubicacion1 IS NULL OR EXISTS (SELECT 1 FROM #ChildUBIs cu WHERE cu.ubiId = DMO.ubiId))
                  AND @cfgKardexMuestraAnulaciones = 1
                GROUP BY DMO.proId

                UNION ALL

                -- Branch 4: saldo del cierre (pre-materializado)
                SELECT SC.proId, 0, 0, 0, 0, SUM(SC.Saldo)
                FROM #SaldoCosto SC
                GROUP BY SC.proId
            ) DT
            GROUP BY proId
        ) QRY ON QRY.proId = PRO.proId
        LEFT JOIN olComun.dbo.CategoriasProductos CPR WITH (NOLOCK) ON PRO.cprId = CPR.cprId
    WHERE ((@Incluir_Activos1 = 0 AND PRO.proTipo = 0) OR (@Incluir_Activos1 = 1 AND PRO.proTipo IN(0,2,4,5)))
      AND (@Categoria_Producto1 IS NULL OR PRO.cprId IN (SELECT cprId FROM #ChildCPRs))
      AND (ISNULL(PRO.marId,0) = @Marca1 OR @Marca1 IS NULL)
    GROUP BY PRO.proCodigo
    HAVING SUM(ISNULL(QRY.Saldo,0)) > 0
    ORDER BY PRO.proCodigo

    DROP TABLE #ChildUBIs
    DROP TABLE #ChildCPRs
    DROP TABLE #MaeMovi
    DROP TABLE #HistFechas
    DROP TABLE #SaldoCosto
  `;
}

async function syncBriloStock(sucursalId, fechaArg) {
  const mapeo = SUCURSAL_UBI_MAP[sucursalId];
  if (!mapeo) {
    console.error(`\nERROR: No hay mapeo de Brilo para sucursal_id=${sucursalId}.`);
    console.error(`Sucursales disponibles:`);
    Object.entries(SUCURSAL_UBI_MAP).forEach(([id, m]) =>
      console.error(`  sucursal_id=${id}  →  ubiId=${m.ubiId}  (${m.nombre})`)
    );
    console.error(`\nPara agregar una nueva, editar SUCURSAL_UBI_MAP en este archivo.`);
    process.exit(1);
  }
  const { ubiId, nombre: sucursalNombre } = mapeo;

  // Fecha para el kardex: si se pasa por argumento usa esa, si no usa hoy
  let fechaKardex = new Date();
  if (fechaArg) {
    fechaKardex = new Date(fechaArg + 'T12:00:00');
    console.log(`Usando fecha histórica: ${fechaArg}`);
  }

  console.log(`Conectando a Brilo (SQL Server)...`);
  const pool = await sql.connect(BRILO_CFG);
  console.log(`OK`);

  const pg = new Pool(PG_CFG);
  await pg.query('SELECT 1');
  console.log(`Conectado a compras_db (PostgreSQL)`);

  // 1. Obtener productos existentes en inventarios para esta sucursal
  const { rows: invItems } = await pg.query(`
    SELECT i.id, i.producto_id, p.codigo
    FROM inventarios i
    JOIN productos p ON p.id = i.producto_id
    WHERE i.sucursal_id = $1
  `, [sucursalId]);
  console.log(`\nProductos en inventario sucursal ${sucursalId}: ${invItems.length}`);

  // Mapa codigo → inv.id para productos que ya tienen registro
  const invPorCodigo = {};
  const invPorProdId = {};
  for (const item of invItems) {
    if (item.codigo) invPorCodigo[item.codigo.trim()] = item;
    invPorProdId[item.producto_id] = item;
  }

  // 2. Consultar saldos en Brilo (kardex a la fecha indicada)
  console.log(`Ejecutando query kardex en Brilo (${sucursalNombre}, ubiId=${ubiId}, fecha=${fechaSQL(fechaKardex)})...`);
  const query = buildKardexQuery(ubiId, fechaKardex);
  const result = await pool.request().query(query);
  await sql.close();

  // Mapa codigo → saldo final
  const briloMap = {};
  result.recordset.forEach(r => { briloMap[r.Codigo.trim()] = parseFloat(r.SaldoFinal); });
  console.log(`Saldos obtenidos de Brilo: ${result.recordset.length} productos con saldo > 0`);

  // 3. Actualizar inventarios existentes
  let actualizados = 0, enCero = 0;
  for (const item of invItems) {
    const codigo = item.codigo?.trim();
    if (!codigo) continue;
    const stock = briloMap[codigo];
    if (stock !== undefined) {
      await pg.query(
        `UPDATE inventarios SET brilo_stock=$1, brilo_sync_at=NOW() WHERE id=$2`,
        [stock, item.id]
      );
      actualizados++;
    } else {
      await pg.query(
        `UPDATE inventarios SET brilo_stock=0, brilo_sync_at=NOW() WHERE id=$1`,
        [item.id]
      );
      enCero++;
    }
  }

  // 4. Insertar registros faltantes: productos que BRILO tiene con saldo
  //    pero no están en inventarios para esta sucursal
  const codigosBrilo = Object.keys(briloMap);
  const codigosSinReg = codigosBrilo.filter(c => !invPorCodigo[c]);

  let insertados = 0;
  if (codigosSinReg.length > 0) {
    console.log(`\nProductos en Brilo sin registro en inventarios: ${codigosSinReg.length}`);
    // Buscar sus producto_id en nuestra BD
    const { rows: prodsNuevos } = await pg.query(`
      SELECT id, codigo FROM productos WHERE codigo = ANY($1)
    `, [codigosSinReg]);

    for (const p of prodsNuevos) {
      const stock = briloMap[p.codigo.trim()];
      if (stock == null) continue;
      await pg.query(`
        INSERT INTO inventarios (producto_id, sucursal_id, brilo_stock, brilo_sync_at, unidad, fecha_conteo, created_at, updated_at)
        SELECT $1, $2, $3, NOW(), COALESCE(p.unidad, 'u'), NOW(), NOW(), NOW()
        FROM productos p WHERE p.id = $1
        ON CONFLICT (producto_id, sucursal_id) DO UPDATE
          SET brilo_stock = EXCLUDED.brilo_stock, brilo_sync_at = NOW()
      `, [p.id, sucursalId, stock]);
      console.log(`  NUEVO: [${p.codigo}] stock=${stock}`);
      insertados++;
    }
  }

  console.log(`\nSync completado:`);
  console.log(`  Actualizados con saldo: ${actualizados}`);
  console.log(`  En cero (sin dato):     ${enCero}`);
  console.log(`  Insertados nuevos:      ${insertados}`);

  // 5. Top 20
  const { rows: resumen } = await pg.query(`
    SELECT p.codigo, p.nombre, i.brilo_stock, i.seccion
    FROM inventarios i
    JOIN productos p ON p.id = i.producto_id
    WHERE i.sucursal_id = $1 AND i.brilo_stock > 0
    ORDER BY i.brilo_stock DESC
    LIMIT 20
  `, [sucursalId]);

  console.log(`\nTop 20 por saldo (sucursal ${sucursalId}):`);
  resumen.forEach(r =>
    console.log(`  [${(r.seccion || '---').padEnd(12)}] ${r.nombre.padEnd(45)} ${Number(r.brilo_stock).toFixed(4)}`)
  );

  await pg.end();
}

const sucursalId = parseInt(process.argv[2], 10);
const fechaArg   = process.argv[3] || null;  // opcional: 'YYYY-MM-DD'

if (!sucursalId) {
  console.log('Uso: node sync_brilo_stock_inventario.js <sucursal_id> [fecha YYYY-MM-DD]');
  console.log('Sucursales disponibles:');
  Object.entries(SUCURSAL_UBI_MAP).forEach(([id, m]) =>
    console.log(`  sucursal_id=${id}  →  ${m.nombre}`)
  );
  process.exit(0);
}

console.log(`\nSync Brilo → compras_db | sucursal_id=${sucursalId} | fecha=${fechaArg || 'hoy'}`);
syncBriloStock(sucursalId, fechaArg).catch(e => { console.error('Error:', e.message); process.exit(1); });
