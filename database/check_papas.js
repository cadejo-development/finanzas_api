const sql = require('mssql');
const cfg = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olCompras',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 20000 },
};
async function main() {
  const pool = await sql.connect(cfg);

  console.log('\n=== TiposDocCom (tipos y signos) ===');
  const r = await pool.request().query(`
    SELECT tdcoCodigo, tdcoNombre, tdcoSignoCompra, tdcoEsCompra, tdcoHabilitado
    FROM TiposDocCom
    ORDER BY tdcoCodigo
  `);
  console.table(r.recordset);

  console.log('\n=== Últimas 5 compras de PAPAS con signo correcto ===');
  const r2 = await pool.request().query(`
    SELECT TOP 5
      MCO.mcoFecha,
      MCO.mcoTipoDoc,
      TDOCC.tdcoSignoCompra,
      D.dcoCantUnidad,
      D.dcoUniNombre,
      D.dcoUniCosto,
      D.dcoUniCosto * TDOCC.tdcoSignoCompra AS costo_unitario_neto,
      D.dcoTotalLinea * TDOCC.tdcoSignoCompra AS total_neto
    FROM olCompras.dbo.detCompras D WITH(NOLOCK)
    INNER JOIN olCompras.dbo.maeCompras MCO WITH(NOLOCK)
      ON MCO.mcoId = D.mcoId
    INNER JOIN olCompras.dbo.TiposDocCom TDOCC WITH(NOLOCK)
      ON MCO.mcoTipoDoc = TDOCC.tdcoCodigo
    WHERE D.proId = 25704
      AND MCO.mcoAnulada = 0
      AND MCO.mcoPosteada = 1
    ORDER BY MCO.mcoFecha DESC
  `);
  console.table(r2.recordset);

  // Costo promedio últimos 3 meses con signo
  console.log('\n=== Costo promedio 3 meses con signo (todos los productos activos en recetas) ===');
  const r3 = await pool.request().query(`
    SELECT TOP 5
      D.proId,
      SUM(D.dcoTotalLinea  * TDOCC.tdcoSignoCompra) AS total_monto,
      SUM(D.dcoCantidad    * TDOCC.tdcoSignoCompra) AS total_cantidad,
      SUM(D.dcoTotalLinea  * TDOCC.tdcoSignoCompra) /
        NULLIF(SUM(D.dcoCantidad * TDOCC.tdcoSignoCompra), 0) AS costo_promedio_3m
    FROM olCompras.dbo.detCompras D WITH(NOLOCK)
    INNER JOIN olCompras.dbo.maeCompras MCO WITH(NOLOCK)
      ON MCO.mcoId = D.mcoId
    INNER JOIN olCompras.dbo.TiposDocCom TDOCC WITH(NOLOCK)
      ON MCO.mcoTipoDoc = TDOCC.tdcoCodigo
    WHERE MCO.mcoAnulada = 0
      AND MCO.mcoPosteada = 1
      AND MCO.mcoFecha >= DATEADD(MONTH, -3, GETDATE())
    GROUP BY D.proId
    ORDER BY D.proId
  `);
  console.table(r3.recordset);

  await pool.close();
}
main().catch(e => { console.error(e.message); process.exit(1); });
