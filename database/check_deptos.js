require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

const { Pool } = require('pg');
const pg = new Pool({
  host: process.env.DB_HOST,
  port: 5432, database: 'core_db', user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD, ssl: { rejectUnauthorized: false },
});

const NUEVOS_CODIGOS = [
  '2536','2537','2538','2539','2540','2541','2542','2543',
  '2544','2545','2546','2547','2548','2549','2550','2551','2552',
  '2553','2554','2555','2557'
];

// sucursal_id → departamento_id (solo para los nuevos)
// Derivado de: deptos por sucursal + BRILO dept para SUC-CM
const SUC_A_DEPTO = {
  // id sucursal → id depto
  // SUC-AE1 → RES_AE1 (25)
  // SUC-LL  → RES_PL  (20)
  // SUC-ZR  → RES_ZR  (24)
  // SUC-GU  → RES_GUI (18)
  // SUC-SE  → RES_SE  (23)
  // SUC-CM  → BOD     (15) — para 2555 que viene de Bodega en BRILO
};

async function main() {
  // Resolver IDs de sucursales
  const sucRes = await pg.query(`SELECT id, codigo FROM sucursales`);
  const sucById = {};
  sucRes.rows.forEach(r => { sucById[r.codigo] = r.id; });

  const depRes = await pg.query(`SELECT id, codigo FROM departamentos WHERE activo = true`);
  const depById = {};
  depRes.rows.forEach(r => { depById[r.codigo] = r.id; });

  // Mapa: sucursal_id → departamento_id
  const map = {
    [sucById['SUC-AE1']]: depById['RES_AE1'],  // 25
    [sucById['SUC-LL']]:  depById['RES_PL'],    // 20
    [sucById['SUC-ZR']]:  depById['RES_ZR'],    // 24
    [sucById['SUC-GU']]:  depById['RES_GUI'],   // 18
    [sucById['SUC-SE']]:  depById['RES_SE'],    // 23
    [sucById['SUC-CM']]:  depById['BOD'],       // 15 (Bodega)
  };

  // Obtener nuevos empleados sin depto
  const emps = await pg.query(`
    SELECT id, codigo, nombres, apellidos, sucursal_id, departamento_id
    FROM empleados
    WHERE codigo = ANY($1) AND departamento_id IS NULL
  `, [NUEVOS_CODIGOS]);

  console.log(`Empleados sin departamento: ${emps.rows.length}\n`);

  let actualizados = 0;
  for (const e of emps.rows) {
    const deptoId = map[e.sucursal_id];
    if (!deptoId) {
      console.log(`  ⚠ Sin mapeo de depto para ${e.codigo} | ${e.nombres} ${e.apellidos} (sucursal_id=${e.sucursal_id})`);
      continue;
    }
    await pg.query(
      `UPDATE empleados SET departamento_id=$1, updated_at=NOW() WHERE id=$2`,
      [deptoId, e.id]
    );
    console.log(`  ✓ ${e.codigo} | ${e.nombres} ${e.apellidos} → depto_id=${deptoId}`);
    actualizados++;
  }

  console.log(`\n✓ ${actualizados} empleados actualizados`);

  // Verificar resultado
  const check = await pg.query(`
    SELECT e.codigo, e.nombres, e.apellidos, s.codigo AS sucursal, d.nombre AS departamento
    FROM empleados e
    JOIN sucursales s ON s.id = e.sucursal_id
    LEFT JOIN departamentos d ON d.id = e.departamento_id
    WHERE e.codigo = ANY($1)
    ORDER BY s.codigo, e.apellidos
  `, [NUEVOS_CODIGOS]);

  console.log('\n=== Estado final ===');
  check.rows.forEach(e =>
    console.log(`  ${e.codigo} | ${e.nombres} ${e.apellidos} | ${e.sucursal} | ${e.departamento ?? 'SIN DEPTO'}`)
  );

  await pg.end();
}
main().catch(e => console.error('ERROR:', e.message));
