const sql = require('mssql');
const cfg = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olRestaurante',
  options: { trustServerCertificate: true, encrypt: false, requestTimeout: 60000 }
};

sql.connect(cfg).then(async pool => {
  const r = await pool.request().query(`
    SELECT LTRIM(RTRIM(CPR.cprNombre)) AS categoria, COUNT(*) AS cnt
    FROM olRestaurante.dbo.maeCuentasRst MCT WITH (NOLOCK)
    INNER JOIN olRestaurante.dbo.detCuentasRst DET WITH (NOLOCK) ON DET.mctrstId = MCT.mctrstId
    INNER JOIN olComun.dbo.Productos PRO WITH (NOLOCK) ON PRO.proId = DET.proId
    LEFT JOIN olComun.dbo.CategoriasProductos CPR WITH (NOLOCK) ON CPR.cprId = PRO.cprId
    WHERE MCT.mctrstEliminado = 0
      AND DET.dctrstEliminado = 0
      AND DET.dctrstIdModificadorDe IS NULL
      AND MCT.sucIdOrigenSync IN (3,6,7,8,10,11,12,13,19)
      AND CAST(MCT.mctrstFecHoraCerrada AT TIME ZONE 'UTC'
               AT TIME ZONE 'Central America Standard Time' AS DATE)
          BETWEEN '2026-05-01' AND '2026-05-11'
    GROUP BY CPR.cprNombre
    ORDER BY cnt DESC
  `);
  r.recordset.forEach(row => console.log(String(row.cnt).padStart(7), JSON.stringify(row.categoria)));
  pool.close();
}).catch(e => { console.error(e.message); process.exit(1); });
