require('dotenv').config();
const { Client } = require('pg');

const pgRrhh = new Client({
  host: process.env.DB_HOST_RRHH, port: process.env.DB_PORT_RRHH || 5432,
  database: process.env.DB_DATABASE_RRHH, user: process.env.DB_USERNAME_RRHH,
  password: process.env.DB_PASSWORD_RRHH, ssl: { rejectUnauthorized: false },
});

async function main() {
  await pgRrhh.connect();

  // Restaurar estado=activo en planilla_lineas para id=338 (Immer) y id=647 (Enrique)
  const r = await pgRrhh.query(
    "UPDATE planilla_lineas SET estado = 'activo', updated_at = NOW() WHERE planilla_id IN (10, 11) AND empleado_id IN (338, 647) RETURNING planilla_id, empleado_id, estado, salario_proporcional"
  );
  console.log('Líneas restauradas:', r.rows.length);
  r.rows.forEach(x => console.log(`  planilla=${x.planilla_id} emp=${x.empleado_id} → ${x.estado} sal=${x.salario_proporcional}`));
  console.log('NOTA: Recalcular las planillas en el portal para actualizar los montos (salario cambió de 1791→408.80 y 991→408.80)');

  await pgRrhh.end();
}
main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
