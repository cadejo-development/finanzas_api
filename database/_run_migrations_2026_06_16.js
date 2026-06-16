/**
 * Ejecuta las 2 migraciones del 2026-06-16 directamente en PostgreSQL.
 *
 * Migración 1 (core_db → ventas_ordenes):
 *   Agrega fecha_facturacion y fecha_entrega a ventas_ordenes
 *
 * Migración 2 (compras_db → receta_ingredientes):
 *   Elimina los 968 duplicados de sub_receta_id y crea índices únicos parciales
 */

const { Pool } = require('pg');

const SSL = { rejectUnauthorized: false };

const pgCore = new Pool({
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432, database: 'compras_db', user: 'cadejo_admin',
  password: 'Holamundo#3..', ssl: SSL,
});

const pgCompras = new Pool({
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432, database: 'compras_db', user: 'cadejo_admin',
  password: 'Holamundo#3..', ssl: SSL,
});

async function run() {
  // ── Migración 1: agregar fecha_facturacion y fecha_entrega a ventas_ordenes ──
  console.log('\n[1/2] Agregando columnas a ventas_ordenes...');

  const colCheck = await pgCore.query(`
    SELECT column_name
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name   = 'ventas_ordenes'
      AND column_name  IN ('fecha_facturacion', 'fecha_entrega')
  `);
  const existing = colCheck.rows.map(r => r.column_name);

  if (existing.includes('fecha_facturacion') && existing.includes('fecha_entrega')) {
    console.log('  Ya existen ambas columnas — nada que hacer.');
  } else {
    if (!existing.includes('fecha_facturacion')) {
      await pgCore.query(`ALTER TABLE ventas_ordenes ADD COLUMN fecha_facturacion DATE NULL`);
      console.log('  fecha_facturacion agregada.');
    }
    if (!existing.includes('fecha_entrega')) {
      await pgCore.query(`ALTER TABLE ventas_ordenes ADD COLUMN fecha_entrega DATE NULL`);
      console.log('  fecha_entrega agregada.');
    }
  }

  // ── Migración 2: limpiar duplicados + índices únicos parciales en receta_ingredientes ──
  console.log('\n[2/2] receta_ingredientes — limpiar duplicados y crear índices únicos...');

  // Contar duplicados antes
  const { rows: antes } = await pgCompras.query(`
    SELECT COUNT(*) AS total
    FROM receta_ingredientes ri
    WHERE ri.sub_receta_id IS NOT NULL
      AND EXISTS (
        SELECT 1 FROM receta_ingredientes ri2
        WHERE ri2.receta_id = ri.receta_id
          AND ri2.sub_receta_id = ri.sub_receta_id
          AND ri2.id < ri.id
      )
  `);
  console.log(`  Duplicados a eliminar: ${antes[0].total}`);

  // Eliminar duplicados (conservar el id mínimo por grupo)
  const del = await pgCompras.query(`
    DELETE FROM receta_ingredientes
    WHERE id NOT IN (
      SELECT MIN(id)
      FROM receta_ingredientes
      GROUP BY receta_id,
               COALESCE(producto_id::text, ''),
               COALESCE(sub_receta_id::text, '')
    )
  `);
  console.log(`  Filas eliminadas: ${del.rowCount}`);

  // Índice único parcial para producto_id
  const idxProd = await pgCompras.query(`
    SELECT 1 FROM pg_indexes
    WHERE schemaname = 'public' AND indexname = 'uq_receta_ing_producto'
  `);
  if (idxProd.rows.length === 0) {
    await pgCompras.query(`
      CREATE UNIQUE INDEX uq_receta_ing_producto
      ON receta_ingredientes (receta_id, producto_id)
      WHERE producto_id IS NOT NULL
    `);
    console.log('  Índice uq_receta_ing_producto creado.');
  } else {
    console.log('  Índice uq_receta_ing_producto ya existe.');
  }

  // Índice único parcial para sub_receta_id
  const idxSub = await pgCompras.query(`
    SELECT 1 FROM pg_indexes
    WHERE schemaname = 'public' AND indexname = 'uq_receta_ing_subreceta'
  `);
  if (idxSub.rows.length === 0) {
    await pgCompras.query(`
      CREATE UNIQUE INDEX uq_receta_ing_subreceta
      ON receta_ingredientes (receta_id, sub_receta_id)
      WHERE sub_receta_id IS NOT NULL
    `);
    console.log('  Índice uq_receta_ing_subreceta creado.');
  } else {
    console.log('  Índice uq_receta_ing_subreceta ya existe.');
  }

  // Verificar que no quedan duplicados
  const { rows: despues } = await pgCompras.query(`
    SELECT COUNT(*) AS total
    FROM receta_ingredientes ri
    WHERE ri.sub_receta_id IS NOT NULL
      AND EXISTS (
        SELECT 1 FROM receta_ingredientes ri2
        WHERE ri2.receta_id = ri.receta_id
          AND ri2.sub_receta_id = ri.sub_receta_id
          AND ri2.id < ri.id
      )
  `);
  console.log(`  Duplicados restantes: ${despues[0].total}`);

  console.log('\n✓ Migraciones completadas.');
  await pgCore.end();
  await pgCompras.end();
}

run().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
