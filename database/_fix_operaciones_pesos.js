/**
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

 * Actualiza los pesos de los criterios de auditoría de operaciones
 * según los valores oficiales de Lourdes (total: 100 pts).
 * Luego recalcula calificacion y clasificacion de todas las auditorias evaluadas.
 *
 * node database/_fix_operaciones_pesos.js
 */
const { Pool } = require('pg');

const db = new Pool({
  host: process.env.DB_HOST,
  port: 5432, database: 'compras_db',
  user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
});

// Pesos oficiales: [categoria_orden, orden, peso_nuevo]
// Total: 20 + 30 + 20 + 20 + 10 = 100 puntos
const PESOS = [
  // Mise en Place (20 pts)
  [1, 1, 3], [1, 2, 3], [1, 3, 3], [1, 4, 2], [1, 5, 3],
  [1, 6, 2], [1, 7, 1], [1, 8, 2], [1, 9, 1],
  // Cumplimiento del Procedimiento (30 pts)
  [2, 1, 5], [2, 2, 3], [2, 3, 3], [2, 4, 6], [2, 5, 3],
  [2, 6, 4], [2, 7, 2], [2, 8, 2], [2, 9, 2],
  // Control de Medición y Porcionado (20 pts)
  [3, 1, 5], [3, 2, 4], [3, 3, 2], [3, 4, 4], [3, 5, 2],
  [3, 6, 2], [3, 7, 1],
  // Control de Calidad del Producto Final (20 pts)
  [4, 1, 5], [4, 2, 3], [4, 3, 3], [4, 4, 2], [4, 5, 2],
  [4, 6, 3], [4, 7, 1], [4, 8, 1],
  // Higiene y Seguridad Alimentaria (10 pts)
  [5, 1, 3], [5, 2, 2], [5, 3, 3], [5, 4, 1], [5, 5, 0.5], [5, 6, 0.5],
];

// Clasificación oficial de Lourdes para operaciones
function clasificar(cal) {
  if (cal >= 98) return 'Excelente';
  if (cal >= 90) return 'Muy Bueno';
  if (cal >= 80) return 'Bueno';
  if (cal >= 70) return 'Aceptable';
  return 'Deficiente';
}

async function main() {
  console.log('=== Actualizando pesos de criterios de operaciones ===\n');

  // 1. Mostrar estado actual
  const { rows: antes } = await db.query(`
    SELECT categoria_orden, categoria, SUM(peso) as total_seccion, COUNT(*) as n_criterios
    FROM auditoria_criterios
    WHERE tipo = 'operaciones' AND categoria_orden BETWEEN 1 AND 5
    GROUP BY categoria_orden, categoria ORDER BY categoria_orden
  `);
  console.log('ANTES — pesos por sección:');
  console.table(antes);

  // 2. Actualizar pesos uno a uno
  let actualizados = 0;
  for (const [catOrden, orden, peso] of PESOS) {
    const { rowCount } = await db.query(`
      UPDATE auditoria_criterios
      SET peso = $1, updated_at = NOW()
      WHERE tipo = 'operaciones'
        AND categoria_orden = $2
        AND orden = $3
    `, [peso, catOrden, orden]);
    if (rowCount === 1) actualizados++;
    else console.warn(`  ⚠ No encontrado: categoria_orden=${catOrden}, orden=${orden}`);
  }
  console.log(`\nCriterios actualizados: ${actualizados}/${PESOS.length}`);

  // 3. Verificar totales después
  const { rows: despues } = await db.query(`
    SELECT categoria_orden, categoria, SUM(peso) as total_seccion, COUNT(*) as n_criterios
    FROM auditoria_criterios
    WHERE tipo = 'operaciones' AND categoria_orden BETWEEN 1 AND 5
    GROUP BY categoria_orden, categoria ORDER BY categoria_orden
  `);
  console.log('\nDESPUÉS — pesos por sección:');
  console.table(despues);

  const totalGeneral = despues.reduce((s, r) => s + parseFloat(r.total_seccion), 0);
  console.log(`Total general: ${totalGeneral} pts (debe ser 100)`);

  // 4. Recalcular calificacion y clasificacion para auditorias evaluadas
  console.log('\n=== Recalculando auditorias de operaciones evaluadas ===\n');

  const { rows: auditorias } = await db.query(`
    SELECT ar.id, ar.calificacion AS cal_vieja, ar.clasificacion AS clas_vieja
    FROM auditorias_receta ar
    WHERE ar.tipo = 'operaciones' AND ar.estado = 'evaluada'
    ORDER BY ar.id
  `);

  for (const aud of auditorias) {
    const { rows: items } = await db.query(`
      SELECT ai.resultado, ac.peso
      FROM auditoria_items ai
      JOIN auditoria_criterios ac ON ac.id = ai.criterio_id
      WHERE ai.auditoria_id = $1
        AND ai.resultado IS NOT NULL AND ai.resultado != 'na'
    `, [aud.id]);

    let totalPeso = 0, pesoObtenido = 0;
    for (const it of items) {
      totalPeso += parseFloat(it.peso);
      if (it.resultado === 'cumple') pesoObtenido += parseFloat(it.peso);
    }

    const calNueva = items.length > 0
      ? Math.round((pesoObtenido / totalPeso) * 1000) / 10
      : null;
    const clasNueva = calNueva !== null ? clasificar(calNueva) : null;

    await db.query(`
      UPDATE auditorias_receta
      SET calificacion = $1, clasificacion = $2, updated_at = NOW()
      WHERE id = $3
    `, [calNueva, clasNueva, aud.id]);

    const cambio = aud.cal_vieja != calNueva ? '←CAMBIÓ' : '';
    console.log(`  id=${aud.id}: ${aud.cal_vieja} "${aud.clas_vieja}"  →  ${calNueva} "${clasNueva}" ${cambio}`);
  }

  console.log('\n✓ Listo.');
  await db.end();
}

main().catch(e => { console.error(e.message); process.exit(1); });
