/**
 * Fix: receta_ingredientes guardados como producto_id cuando deben ser sub_receta_id.
 * También corrige unidades incompatibles con el rendimiento_unidad de la sub-receta.
 *
 * Uso:  node database/fix_subreceta_links.js           -- dry run (solo muestra)
 *        node database/fix_subreceta_links.js --apply  -- aplica los cambios
 */
const {Pool} = require('pg');
const apply = process.argv.includes('--apply');

const pg = new Pool({
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432, database: 'compras_db', user: 'cadejo_admin',
  password: 'Holamundo#3..', ssl: {rejectUnauthorized: false},
});

// Grupos de unidades (mismo que el frontend)
const GRUPOS = {
  peso:     ['g', 'gr', 'oz', 'lb', 'kg'],
  volumen:  ['ml', 'oz fl', 'fl oz', 'ozf', 'lt', 'litro', 'galon'],
  discreto: ['u', 'und', 'porcion', 'porción', 'tanda', 'botella', 'caja', 'paquete'],
};
const JERARQUIA = {
  peso:    ['g', 'oz', 'lb', 'kg'],
  volumen: ['ml', 'oz fl', 'lt', 'galon', 'barril'],
};

function grupoDeUnidad(u) {
  const s = (u || '').toLowerCase().trim();
  for (const [g, list] of Object.entries(GRUPOS)) {
    if (list.includes(s)) return g;
  }
  return null;
}

function unidadCompatible(riUnidad, rendUnidad) {
  if (!rendUnidad) return true; // sin constrainta
  const gRend = grupoDeUnidad(rendUnidad);
  const gRI   = grupoDeUnidad(riUnidad);
  if (!gRend || !gRI) return gRend === gRI; // si alguno es null, solo OK si son iguales
  if (gRend !== gRI) return false;
  // Verificar jerarquía
  const jer = JERARQUIA[gRend];
  if (!jer) return true; // discreto — todo el grupo está permitido
  const idxRend = jer.indexOf((rendUnidad || '').toLowerCase().trim());
  const idxRI   = jer.indexOf((riUnidad || '').toLowerCase().trim());
  if (idxRend === -1 || idxRI === -1) return true; // no en jerarquía → no restringir
  return idxRI <= idxRend;
}

async function main() {
  // Obtener todos los casos corregibles
  const { rows } = await pg.query(`
    SELECT
      ri.id ri_id,
      ri.producto_id,
      ri.unidad ri_unidad,
      ri.cantidad_por_plato,
      rec.codigo_origen receta_cod,
      p.codigo p_codigo,
      sr.id sr_id,
      sr.rendimiento_unidad sr_rend_unidad
    FROM receta_ingredientes ri
    JOIN productos p ON p.id = ri.producto_id
    JOIN recetas rec ON rec.id = ri.receta_id AND rec.activa = true
    JOIN recetas sr ON sr.codigo_origen = p.codigo AND sr.activa = true
    WHERE ri.sub_receta_id IS NULL
    ORDER BY ri.id
  `);

  console.log(`\n${apply ? '🔧 APLICANDO' : '🔍 DRY RUN (--apply para ejecutar)'}\n`);
  console.log(`Encontrados: ${rows.length} casos\n`);

  let updated = 0;
  let unitFixed = 0;

  for (const row of rows) {
    const newUnit = unidadCompatible(row.ri_unidad, row.sr_rend_unidad)
      ? row.ri_unidad
      : (row.sr_rend_unidad || row.ri_unidad); // usar rendimiento_unidad como fallback

    const unitChanged = newUnit !== row.ri_unidad;

    console.log(
      `ri=${row.ri_id} | ${row.receta_cod} → ${row.p_codigo} ` +
      `| sub_receta_id=${row.sr_id} ` +
      (unitChanged
        ? `| ⚠️  unidad: ${row.ri_unidad} → ${newUnit} (rend=${row.sr_rend_unidad})`
        : `| unidad: ${row.ri_unidad} ✓`)
    );

    if (apply) {
      await pg.query(
        `UPDATE receta_ingredientes
         SET sub_receta_id = $1, producto_id = NULL, unidad = $2
         WHERE id = $3`,
        [row.sr_id, newUnit, row.ri_id]
      );
      updated++;
      if (unitChanged) unitFixed++;
    }
  }

  console.log(`\n--- Resumen ---`);
  console.log(`Total: ${rows.length} filas`);
  if (apply) {
    console.log(`Actualizadas: ${updated}`);
    console.log(`Unidades corregidas: ${unitFixed}`);
  } else {
    const unitMismatches = rows.filter(r => !unidadCompatible(r.ri_unidad, r.sr_rend_unidad));
    console.log(`Con unidad incompatible: ${unitMismatches.length}`);
    console.log(`\nEjecuta con --apply para aplicar los cambios.`);
  }

  await pg.end();
}

main().catch(e => { console.error(e.message); process.exit(1); });
