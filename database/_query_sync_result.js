const { Pool } = require('pg');
const pg = new Pool({
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432, database: 'core_db', user: 'cadejo_admin',
  password: 'Holamundo#3..', ssl: { rejectUnauthorized: false }
});

async function run() {
  // Hora actual del servidor
  const now = await pg.query('SELECT NOW() as db_now');
  console.log('DB NOW:', now.rows[0].db_now);

  // Los 5 más recientes insertados por el script
  const nuevos = await pg.query(`
    SELECT e.codigo, e.nombres, e.apellidos, e.activo, e.created_at, s.nombre AS sucursal
    FROM empleados e
    LEFT JOIN sucursales s ON s.id = e.sucursal_id
    WHERE e.aud_usuario = 'sync_empleados_to_rds.js'
    ORDER BY e.created_at DESC
    LIMIT 10
  `);

  // Empleados inactivos actualizados recientemente (sin filtro de aud_usuario)
  const inactivados = await pg.query(`
    SELECT e.codigo, e.nombres, e.apellidos, e.updated_at, s.nombre AS sucursal
    FROM empleados e
    LEFT JOIN sucursales s ON s.id = e.sucursal_id
    WHERE e.activo = false
    ORDER BY e.updated_at DESC
    LIMIT 20
  `);

  console.log('\n=== ULTIMOS INSERTADOS POR SCRIPT ===');
  nuevos.rows.forEach(r =>
    console.log('  ' + r.codigo + ' | ' + r.nombres + ' ' + r.apellidos + ' | ' + (r.sucursal || 'Sin sucursal') + ' | ' + r.created_at)
  );

  console.log('\n=== INACTIVOS MAS RECIENTEMENTE ACTUALIZADOS ===');
  inactivados.rows.forEach(r =>
    console.log('  ' + r.codigo + ' | ' + r.nombres + ' ' + r.apellidos + ' | ' + (r.sucursal || 'Sin sucursal') + ' | updated: ' + r.updated_at)
  );

  await pg.end();
}

run().catch(e => { console.error(e.message); process.exit(1); });
