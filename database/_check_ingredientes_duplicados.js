const { Pool } = require('pg');
const pg = new Pool({
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432, database: 'compras_db', user: 'cadejo_admin',
  password: 'Holamundo#3..', ssl: { rejectUnauthorized: false },
});

async function main() {
  // Duplicados por producto_id
  const dupProducto = await pg.query(`
    SELECT ri.receta_id, r.nombre AS receta, ri.producto_id, p.nombre AS producto,
           COUNT(*) AS veces,
           STRING_AGG(ri.id::text, ', ' ORDER BY ri.id) AS ids,
           STRING_AGG(ri.aud_usuario, ', ' ORDER BY ri.id) AS usuarios,
           STRING_AGG(ri.created_at::text, ', ' ORDER BY ri.id) AS fechas
    FROM receta_ingredientes ri
    JOIN recetas r ON r.id = ri.receta_id
    LEFT JOIN productos p ON p.id = ri.producto_id
    WHERE ri.producto_id IS NOT NULL
    GROUP BY ri.receta_id, r.nombre, ri.producto_id, p.nombre
    HAVING COUNT(*) > 1
    ORDER BY r.nombre, p.nombre
  `);

  // Duplicados por sub_receta_id
  const dupSubreceta = await pg.query(`
    SELECT ri.receta_id, r.nombre AS receta, ri.sub_receta_id, sr.nombre AS sub_receta,
           COUNT(*) AS veces,
           STRING_AGG(ri.id::text, ', ' ORDER BY ri.id) AS ids,
           STRING_AGG(ri.aud_usuario, ', ' ORDER BY ri.id) AS usuarios,
           STRING_AGG(ri.created_at::text, ', ' ORDER BY ri.id) AS fechas
    FROM receta_ingredientes ri
    JOIN recetas r ON r.id = ri.receta_id
    LEFT JOIN recetas sr ON sr.id = ri.sub_receta_id
    WHERE ri.sub_receta_id IS NOT NULL
    GROUP BY ri.receta_id, r.nombre, ri.sub_receta_id, sr.nombre
    HAVING COUNT(*) > 1
    ORDER BY r.nombre, sr.nombre
  `);

  console.log('\n=== DUPLICADOS por producto_id ===');
  if (dupProducto.rows.length === 0) {
    console.log('Ninguno.');
  } else {
    dupProducto.rows.forEach(row => {
      console.log(`\nReceta: ${row.receta} (id=${row.receta_id})`);
      console.log(`  Producto: ${row.producto} (id=${row.producto_id}) — aparece ${row.veces}x`);
      console.log(`  IDs filas: ${row.ids}`);
      console.log(`  Usuarios:  ${row.usuarios}`);
      console.log(`  Fechas:    ${row.fechas}`);
    });
  }

  console.log('\n=== DUPLICADOS por sub_receta_id ===');
  if (dupSubreceta.rows.length === 0) {
    console.log('Ninguno.');
  } else {
    dupSubreceta.rows.forEach(row => {
      console.log(`\nReceta: ${row.receta} (id=${row.receta_id})`);
      console.log(`  Sub-receta: ${row.sub_receta} (id=${row.sub_receta_id}) — aparece ${row.veces}x`);
      console.log(`  IDs filas: ${row.ids}`);
      console.log(`  Usuarios:  ${row.usuarios}`);
      console.log(`  Fechas:    ${row.fechas}`);
    });
  }

  console.log(`\nTotal recetas con duplicados: ${dupProducto.rows.length + dupSubreceta.rows.length}`);
  await pg.end();
}

main().catch(e => { console.error(e.message); process.exit(1); });
