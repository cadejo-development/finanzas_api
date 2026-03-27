/**
 * fix_gerencia_financiera_role_rds.js
 * Inserta el rol gerencia_financiera en el sistema 'compras' en RDS (producción)
 * y lo asigna al usuario Juan Jose Lopez Valladares.
 */
const { Client } = require('pg');

const rdsCfg = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432,
  user: 'cadejo_admin',
  password: 'Holamundo#3..',
  database: 'core_db',
  ssl: { rejectUnauthorized: false },
};

async function main() {
  const client = new Client(rdsCfg);
  await client.connect();
  console.log('Conectado a RDS core_db.');

  // 1. Ver sistemas disponibles
  const { rows: sistemas } = await client.query(
    "SELECT id, codigo, nombre FROM systems ORDER BY id"
  );
  console.log('\nSistemas en RDS:');
  sistemas.forEach(s => console.log(`  id=${s.id} | codigo=${s.codigo} | nombre=${s.nombre}`));

  // 2. Obtener system_id de 'compras'
  const compras = sistemas.find(s => s.codigo === 'compras');
  if (!compras) {
    console.error('ERROR: sistema compras no encontrado en RDS');
    await client.end();
    process.exit(1);
  }
  console.log(`\nSistema compras: id=${compras.id}`);

  // 3. Ver roles existentes en compras
  const { rows: rolesExistentes } = await client.query(
    "SELECT id, codigo, nombre FROM roles WHERE system_id = $1 ORDER BY id",
    [compras.id]
  );
  console.log('\nRoles actuales en compras:');
  rolesExistentes.forEach(r => console.log(`  id=${r.id} | codigo=${r.codigo}`));

  // 4. Insertar rol gerencia_financiera si no existe
  const rolExistente = rolesExistentes.find(r => r.codigo === 'gerencia_financiera');
  let rolId;
  if (rolExistente) {
    rolId = rolExistente.id;
    console.log(`\nRol gerencia_financiera ya existe con id=${rolId}`);
  } else {
    const { rows: [nuevoRol] } = await client.query(
      `INSERT INTO roles (nombre, codigo, system_id, created_at, updated_at)
       VALUES ('Gerencia Financiera', 'gerencia_financiera', $1, NOW(), NOW())
       RETURNING id, codigo`,
      [compras.id]
    );
    rolId = nuevoRol.id;
    console.log(`\nRol gerencia_financiera creado con id=${rolId}`);
  }

  // 5. Buscar Juan Jose Lopez
  const { rows: usuarios } = await client.query(
    "SELECT id, name, email FROM users WHERE email ILIKE '%juan%' OR name ILIKE '%juan%' ORDER BY id"
  );
  console.log('\nUsuarios Juan encontrados:');
  usuarios.forEach(u => console.log(`  id=${u.id} | name=${u.name} | email=${u.email}`));

  // Buscar específicamente Juan Jose
  const juanJose = usuarios.find(u =>
    u.name?.toLowerCase().includes('juan') && u.name?.toLowerCase().includes('jose')
  ) || usuarios[0];

  if (!juanJose) {
    console.error('ERROR: No se encontró usuario Juan Jose');
    await client.end();
    process.exit(1);
  }
  console.log(`\nAsignando rol a: id=${juanJose.id} | ${juanJose.name} | ${juanJose.email}`);

  // 6. Verificar si ya tiene el rol asignado
  const { rows: asignacionExistente } = await client.query(
    "SELECT * FROM role_user WHERE user_id = $1 AND role_id = $2",
    [juanJose.id, rolId]
  );

  if (asignacionExistente.length > 0) {
    console.log('El usuario ya tiene este rol asignado.');
  } else {
    await client.query(
      "INSERT INTO role_user (user_id, role_id) VALUES ($1, $2)",
      [juanJose.id, rolId]
    );
    console.log('Rol asignado correctamente.');
  }

  // 7. Verificar roles finales del usuario en compras
  const { rows: rolesFinales } = await client.query(
    `SELECT r.id, r.codigo, r.nombre
     FROM roles r
     JOIN role_user ru ON ru.role_id = r.id
     WHERE ru.user_id = $1 AND r.system_id = $2`,
    [juanJose.id, compras.id]
  );
  console.log(`\nRoles de ${juanJose.name} en compras:`);
  rolesFinales.forEach(r => console.log(`  - ${r.codigo}`));

  await client.end();
  console.log('\n✓ Completado');
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
