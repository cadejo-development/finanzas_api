/**
 * Crea dos nuevos roles de analista RRHH y los asigna a los usuarios correspondientes.
 * - rrhh_analista     → Analista RRHH (Margarita Lara, user_id=40) — acceso senior
 * - rrhh_analista_jr  → Analista RRHH Jr (Mario Villalobos, user_id=50) — acceso junior
 */

const { Client } = require('pg');

const client = new Client({
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432,
  database: 'core_db',
  user: 'cadejo_admin',
  password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
});

async function run() {
  await client.connect();
  console.log('Conectado a PostgreSQL RDS (core_db)');

  // 1. Insertar roles (si no existen)
  const rolesACrear = [
    { nombre: 'Analista RRHH',    codigo: 'rrhh_analista' },
    { nombre: 'Analista RRHH Jr', codigo: 'rrhh_analista_jr' },
  ];

  for (const r of rolesACrear) {
    const existe = await client.query('SELECT id FROM roles WHERE codigo=$1 AND system_id=5', [r.codigo]);
    if (existe.rowCount === 0) {
      const res = await client.query(
        'INSERT INTO roles (nombre, codigo, system_id, is_active) VALUES ($1, $2, 5, true) RETURNING id, nombre, codigo',
        [r.nombre, r.codigo]
      );
      console.log('Rol creado:', res.rows[0]);
    } else {
      console.log(`Rol "${r.codigo}" ya existe (id=${existe.rows[0].id}), saltando.`);
    }
  }

  const insertRoles = await client.query(
    `SELECT id, nombre, codigo FROM roles WHERE codigo IN ('rrhh_analista','rrhh_analista_jr') AND system_id=5`
  );
  console.log('Roles disponibles:', insertRoles.rows);

  const rolAnalista   = insertRoles.rows.find(r => r.codigo === 'rrhh_analista');
  const rolAnalistaJr = insertRoles.rows.find(r => r.codigo === 'rrhh_analista_jr');

  if (!rolAnalista || !rolAnalistaJr) {
    throw new Error('No se obtuvieron los IDs de los roles creados.');
  }

  // 2. Verificar usuarios
  const usuarios = await client.query(`
    SELECT id, name, email FROM users WHERE id IN (40, 50)
  `);
  console.log('Usuarios encontrados:', usuarios.rows);

  // 3. Asignar rrhh_analista a Margarita (user_id=40)
  const existeMargarita = await client.query(
    'SELECT id FROM role_user WHERE role_id=$1 AND user_id=40', [rolAnalista.id]
  );
  if (existeMargarita.rowCount === 0) {
    await client.query('INSERT INTO role_user (role_id, user_id) VALUES ($1, 40)', [rolAnalista.id]);
    console.log(`Rol rrhh_analista (id=${rolAnalista.id}) asignado a user_id=40 (Margarita)`);
  } else {
    console.log(`Margarita ya tiene rrhh_analista, saltando.`);
  }

  // 4. Asignar rrhh_analista_jr a Mario (user_id=50)
  const existeMario = await client.query(
    'SELECT id FROM role_user WHERE role_id=$1 AND user_id=50', [rolAnalistaJr.id]
  );
  if (existeMario.rowCount === 0) {
    await client.query('INSERT INTO role_user (role_id, user_id) VALUES ($1, 50)', [rolAnalistaJr.id]);
    console.log(`Rol rrhh_analista_jr (id=${rolAnalistaJr.id}) asignado a user_id=50 (Mario)`);
  } else {
    console.log(`Mario ya tiene rrhh_analista_jr, saltando.`);
  }

  // 5. Verificar asignaciones
  const asignaciones = await client.query(`
    SELECT u.id, u.name, u.email, r.nombre as rol, r.codigo
    FROM users u
    JOIN role_user ru ON ru.user_id = u.id
    JOIN roles r ON r.id = ru.role_id
    WHERE u.id IN (40, 50)
    ORDER BY u.id, r.codigo
  `);
  console.log('\nAsignaciones finales:');
  asignaciones.rows.forEach(row => {
    console.log(`  ${row.name} (${row.email}) → ${row.rol} [${row.codigo}]`);
  });

  await client.end();
  console.log('\nListo.');
}

run().catch(err => {
  console.error('Error:', err.message);
  client.end();
  process.exit(1);
});
