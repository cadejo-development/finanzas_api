/**
 * sync_empleados_to_rds.js
 * Copia cargos y empleados desde Railway core_db → RDS core_db
 * Usa ON CONFLICT DO UPDATE — no borra nada existente.
 */
const { Client } = require('pg');

const railwayCfg = {
  host: 'centerbeam.proxy.rlwy.net',
  port: 42433,
  user: 'postgres',
  password: 'kOGUAWGcBiGgYWFdKXjEDmHHnDEQLAVy',
  database: 'railway',
  ssl: { rejectUnauthorized: false },
};

const rdsCfg = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432,
  user: 'cadejo_admin',
  password: 'Holamundo#3..',
  database: 'core_db',
  ssl: { rejectUnauthorized: false },
};

async function main() {
  const src = new Client(railwayCfg);
  const dst = new Client(rdsCfg);
  await src.connect();
  await dst.connect();
  console.log('Conectado a Railway y RDS.');

  // ── 0. Sucursales ─────────────────────────────────────────────────────────
  const { rows: sucursales } = await src.query('SELECT * FROM sucursales ORDER BY id');
  console.log(`\nSucursales en Railway: ${sucursales.length}`);
  let sIns = 0;
  for (const r of sucursales) {
    await dst.query(
      `INSERT INTO sucursales (id, codigo, nombre, tipo_sucursal_id, aud_usuario, created_at, updated_at)
       VALUES ($1,$2,$3,$4,$5,$6,$7)
       ON CONFLICT (id) DO UPDATE SET
         codigo           = EXCLUDED.codigo,
         nombre           = EXCLUDED.nombre,
         tipo_sucursal_id = EXCLUDED.tipo_sucursal_id,
         updated_at       = EXCLUDED.updated_at`,
      [r.id, r.codigo, r.nombre, r.tipo_sucursal_id, r.aud_usuario, r.created_at, r.updated_at]
    );
    sIns++;
  }
  await dst.query(`SELECT setval('sucursales_id_seq', (SELECT MAX(id) FROM sucursales))`);
  console.log(`  Sincronizadas: ${sIns}`);

  // ── 1. Cargos ──────────────────────────────────────────────────────────────
  const { rows: cargos } = await src.query('SELECT * FROM cargos ORDER BY id');
  console.log(`\nCargos en Railway: ${cargos.length}`);
  let cIns = 0, cUpd = 0;
  for (const r of cargos) {
    const res = await dst.query(
      `INSERT INTO cargos (id, codigo, nombre, activo, aud_usuario, created_at, updated_at)
       VALUES ($1,$2,$3,$4,$5,$6,$7)
       ON CONFLICT (id) DO UPDATE SET
         codigo      = EXCLUDED.codigo,
         nombre      = EXCLUDED.nombre,
         activo      = EXCLUDED.activo,
         updated_at  = EXCLUDED.updated_at`,
      [r.id, r.codigo, r.nombre, r.activo, r.aud_usuario, r.created_at, r.updated_at]
    );
    res.rowCount === 1 ? cIns++ : cUpd++;
  }
  await dst.query(`SELECT setval('cargos_id_seq', (SELECT MAX(id) FROM cargos))`);
  console.log(`  Insertados: ${cIns} | Actualizados: ${cUpd}`);

  // ── 2. Empleados ───────────────────────────────────────────────────────────
  const { rows: empleados } = await src.query('SELECT * FROM empleados ORDER BY id');
  console.log(`\nEmpleados en Railway: ${empleados.length}`);
  let eIns = 0, eUpd = 0;
  for (const r of empleados) {
    const res = await dst.query(
      `INSERT INTO empleados (id, codigo, nombres, apellidos, email, cargo_id, sucursal_id, activo, user_id, aud_usuario, created_at, updated_at)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12)
       ON CONFLICT (id) DO UPDATE SET
         codigo      = EXCLUDED.codigo,
         nombres     = EXCLUDED.nombres,
         apellidos   = EXCLUDED.apellidos,
         email       = EXCLUDED.email,
         cargo_id    = EXCLUDED.cargo_id,
         sucursal_id = EXCLUDED.sucursal_id,
         activo      = EXCLUDED.activo,
         user_id     = EXCLUDED.user_id,
         updated_at  = EXCLUDED.updated_at`,
      [r.id, r.codigo, r.nombres, r.apellidos, r.email, r.cargo_id, r.sucursal_id,
       r.activo, r.user_id, r.aud_usuario, r.created_at, r.updated_at]
    );
    res.rowCount === 1 ? eIns++ : eUpd++;
  }
  await dst.query(`SELECT setval('empleados_id_seq', (SELECT MAX(id) FROM empleados))`);
  console.log(`  Insertados: ${eIns} | Actualizados: ${eUpd}`);

  await src.end();
  await dst.end();

  console.log('\n==========================================');
  console.log('✓ Sync completado');
  console.log(`  Sucursales: ${sucursales.length}`);
  console.log(`  Cargos:     ${cargos.length}`);
  console.log(`  Empleados:  ${empleados.length}`);
  console.log('==========================================');
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
