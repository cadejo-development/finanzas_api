require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const sql = require('mssql');
const { Client } = require('pg');

const MSSQL_CFG = {
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_PASSWORD_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: 2033, database: 'olComun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000, requestTimeout: 60000 },
};
const PG_CFG = {
  host: process.env.DB_HOST_COMPRAS, port: 5432,
  database: process.env.DB_DATABASE_COMPRAS,
  user: process.env.DB_USERNAME_COMPRAS, password: process.env.DB_PASSWORD_COMPRAS,
  ssl: { rejectUnauthorized: false },
};

async function main() {
  const pool = await sql.connect(MSSQL_CFG);
  const pg   = new Client(PG_CFG);
  await pg.connect();

  // ── 1. Traer TODOS los productos activos de Brilo con su tipo ───────────
  // No filtramos por prefijo MR porque algunos productos del RDS tienen
  // códigos custom (AEMR..., AE1MR..., PT...) que en Brilo existen con ese
  // mismo código pero no empiezan con MR. Necesitamos el map completo.
  const briloRes = await pool.request().query(`
    SELECT
      p.proCodigo      AS codigo,
      p.proNombre      AS nombre,
      p.proTipo        AS tipo,
      u.uniAbreviacion AS unidad
    FROM dbo.Productos p WITH(NOLOCK)
    LEFT JOIN dbo.Unidades u WITH(NOLOCK) ON u.uniId = p.uniId
    WHERE p.proActivo = 1
  `);

  const briloMap = {};
  briloRes.recordset.forEach(r => { briloMap[r.codigo?.trim()] = r; });
  console.log(`Brilo MR activos: ${briloRes.recordset.length}`);

  // ── 2. Productos actuales en RDS ───────────────────────────────────────
  const { rows: rdsProds } = await pg.query(`
    SELECT p.id, p.codigo FROM productos p
    JOIN categorias c ON c.id = p.categoria_id
    WHERE p.activo = true AND c.key ILIKE 'MR%' AND p.codigo IS NOT NULL
  `);
  const rdsMap = {};
  rdsProds.forEach(p => { rdsMap[p.codigo?.trim()] = p.id; });
  console.log(`RDS MR activos: ${rdsProds.length}`);

  // ── 3. Sincronizar es_inventariado para los que ya existen ────────────
  await pg.query('BEGIN');
  let actualizados = 0;
  for (const [codigo, id] of Object.entries(rdsMap)) {
    const b = briloMap[codigo];
    const esInv = b ? b.tipo === 0 : false;
    await pg.query(
      `UPDATE productos SET es_inventariado = $1 WHERE id = $2`,
      [esInv, id]
    );
    actualizados++;
  }
  await pg.query('COMMIT');
  console.log(`\n✓ es_inventariado sincronizado para ${actualizados} MR en RDS`);

  // ── 4. Importar los MR con código estándar en Brilo que faltan en RDS ──
  const faltantes = briloRes.recordset.filter(r =>
    r.codigo?.trim().startsWith('MR') && !rdsMap[r.codigo?.trim()]
  );
  console.log(`\nMR faltantes en RDS: ${faltantes.length}`);

  // Necesitamos la categoria_id de MR-01 como fallback (cat de materias primas general)
  const { rows: catRows } = await pg.query(
    `SELECT id, key FROM categorias WHERE key ILIKE 'MR%' ORDER BY key LIMIT 10`
  );
  // Usar MR-01 como categoria por defecto para los faltantes
  const catDefault = catRows.find(c => c.key === 'MR-01')?.id ?? catRows[0]?.id;
  console.log(`Categoría default para faltantes: MR-01 (id=${catDefault})`);

  await pg.query('BEGIN');
  let insertados = 0;
  for (const r of faltantes) {
    const codigo = r.codigo?.trim();
    const nombre = r.nombre?.trim() ?? codigo;
    const unidad = r.unidad?.toLowerCase()?.trim() ?? 'u';
    await pg.query(`
      INSERT INTO productos (
        codigo, nombre, unidad, categoria_id,
        activo, es_inventariado, costo, precio,
        origen, aud_usuario, modificado_localmente
      ) VALUES ($1,$2,$3,$4, true,$5, 0, 0, 'restaurante','sync-brilo',false)
      ON CONFLICT (codigo) DO UPDATE SET activo=true, es_inventariado=$5
    `, [codigo, nombre, unidad, catDefault, r.tipo === 0]);
    insertados++;
    console.log(`  + ${codigo.padEnd(18)} ${nombre.substring(0,40)}`);
  }
  await pg.query('COMMIT');
  console.log(`\n✓ Insertados/actualizados: ${insertados}`);

  // ── 5. Estadísticas finales ────────────────────────────────────────────
  const { rows: stats } = await pg.query(`
    SELECT
      COUNT(*) FILTER (WHERE activo=true) as total_activos,
      COUNT(*) FILTER (WHERE activo=true AND es_inventariado=true) as inventariados
    FROM productos p
    JOIN categorias c ON c.id = p.categoria_id
    WHERE c.key ILIKE 'MR%'
  `);
  console.log(`\n=== Números finales MR ===`);
  console.log(`Total MR activos en RDS:     ${stats[0].total_activos}`);
  console.log(`MR inventariados (es_inv=T): ${stats[0].inventariados}`);
  console.log(`MR no inventariados:         ${stats[0].total_activos - stats[0].inventariados}`);

  await pg.end();
  pool.close();
}
main().catch(e => { console.error('Fatal:', e.message); process.exit(1); });
