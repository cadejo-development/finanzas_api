/**
 * Fix: receta_ingredientes con PL20/SUBR/CP guardados como producto_id
 * cuando deben ser sub_receta_id. Solo afecta esos prefijos.
 *
 * Uso: node database/fix_pl_subr_links.js          -- dry run
 *       node database/fix_pl_subr_links.js --apply  -- aplica
 */
const {Pool} = require('pg');
const apply = process.argv.includes('--apply');

const pg = new Pool({
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432, database: 'compras_db', user: 'cadejo_admin',
  password: 'Holamundo#3..', ssl: {rejectUnauthorized: false},
});

// Grupos/jerarquía igual que el frontend
const JERARQUIA = {
  peso:    ['g', 'oz', 'lb', 'kg'],
  volumen: ['ml', 'oz fl', 'lt', 'galon', 'barril'],
};
const GRUPOS = {
  peso:     ['g', 'gr', 'oz', 'lb', 'kg'],
  volumen:  ['ml', 'oz fl', 'fl oz', 'ozf', 'lt', 'litro', 'galon', 'barril'],
  discreto: ['u', 'und', 'porcion', 'porción', 'tanda', 'botella', 'caja', 'paquete'],
};

function grupo(u) {
  const s = (u||'').toLowerCase().trim();
  for (const [g, list] of Object.entries(GRUPOS)) if (list.includes(s)) return g;
  return null;
}

function esCompatible(riUnidad, rendUnidad) {
  const u = (riUnidad||'').toLowerCase().trim();
  const r = (rendUnidad||'').toLowerCase().trim();
  if (!r || u === r) return true;
  const gu = grupo(u), gr = grupo(r);
  if (!gu || !gr || gu !== gr) return false;
  const jer = JERARQUIA[gu];
  if (!jer) return false; // discreto distinto
  const iu = jer.indexOf(u), ir = jer.indexOf(r);
  if (iu === -1 || ir === -1) return false;
  return iu <= ir;
}

async function main() {
  const { rows } = await pg.query(`
    SELECT
      ri.id          ri_id,
      ri.unidad      ri_unidad,
      rec.codigo_origen receta_cod,
      p.codigo       p_codigo,
      sr.id          sr_id,
      sr.rendimiento_unidad sr_rend_unidad
    FROM receta_ingredientes ri
    JOIN productos p  ON p.id   = ri.producto_id
    JOIN recetas rec  ON rec.id = ri.receta_id AND rec.activa = true
    JOIN recetas sr   ON sr.codigo_origen = p.codigo AND sr.activa = true
    WHERE ri.sub_receta_id IS NULL
      AND (p.codigo LIKE 'PL20%' OR p.codigo LIKE 'SUBR%' OR p.codigo LIKE 'CP%')
    ORDER BY ri.id
  `);

  console.log(`\n${apply ? '🔧 APLICANDO' : '🔍 DRY RUN'} — ${rows.length} casos\n`);

  let updated = 0, unitFixed = 0;

  for (const row of rows) {
    // Si rendimiento definido y unidad incompatible → corregir unidad
    const needsUnitFix = row.sr_rend_unidad && !esCompatible(row.ri_unidad, row.sr_rend_unidad);
    const newUnit = needsUnitFix ? row.sr_rend_unidad : row.ri_unidad;

    const unitNote = needsUnitFix ? ` ⚠️  unidad: ${row.ri_unidad} → ${newUnit}` : ` unidad: ${row.ri_unidad} ✓`;
    console.log(`ri=${row.ri_id} | ${row.receta_cod} → ${row.p_codigo} | sub_receta_id=${row.sr_id}${unitNote}`);

    if (apply) {
      await pg.query(
        `UPDATE receta_ingredientes SET sub_receta_id = $1, producto_id = NULL, unidad = $2 WHERE id = $3`,
        [row.sr_id, newUnit, row.ri_id]
      );
      updated++;
      if (needsUnitFix) unitFixed++;
    }
  }

  console.log(`\n--- Resumen ---`);
  console.log(`Total: ${rows.length}`);
  if (apply) {
    console.log(`Actualizados: ${updated}`);
    console.log(`Unidades corregidas: ${unitFixed}`);
  }
  await pg.end();
}

main().catch(e => { console.error(e.message); process.exit(1); });
