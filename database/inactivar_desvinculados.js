/**
 * inactivar_desvinculados.js
 * Inactiva empleados cuya fecha efectiva de desvinculación ya llegó.
 * Puerto Node.js de: php artisan rrhh:inactivar-desvinculados
 *
 * Uso: node database/inactivar_desvinculados.js [--dry-run]
 */
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const { Client } = require('pg');

const DRY_RUN = process.argv.includes('--dry-run');

const cfgCore = {
  host:     process.env.DB_HOST,
  port:     parseInt(process.env.DB_PORT ?? '5432'),
  database: process.env.DB_DATABASE ?? 'core_db',
  user:     process.env.DB_USERNAME ?? 'cadejo_admin',
  password: process.env.DB_PASSWORD,
  ssl:      { rejectUnauthorized: false },
};

const cfgRrhh = {
  host:     process.env.DB_HOST_RRHH ?? process.env.DB_HOST,
  port:     parseInt(process.env.DB_PORT_RRHH ?? '5432'),
  database: process.env.DB_DATABASE_RRHH ?? 'rrhh_db',
  user:     process.env.DB_USERNAME_RRHH ?? process.env.DB_USERNAME ?? 'cadejo_admin',
  password: process.env.DB_PASSWORD_RRHH ?? process.env.DB_PASSWORD,
  ssl:      { rejectUnauthorized: false },
};

async function main() {
  const hoy = new Date().toISOString().slice(0, 10);

  if (DRY_RUN) console.log('⚠ Modo --dry-run: no se realizarán cambios');
  console.log(`\n=== Inactivar Empleados Desvinculados (${hoy}) ===\n`);

  const core = new Client(cfgCore);
  const rrhh = new Client(cfgRrhh);

  try {
    await core.connect();
    await rrhh.connect();
    console.log('PostgreSQL OK (core_db + rrhh_db)');

    // IDs de empleados activos en core_db
    const activosRes = await core.query('SELECT id::text FROM empleados WHERE activo = true');
    const activosIds = activosRes.rows.map(r => r.id);

    if (activosIds.length === 0) {
      console.log('No hay empleados activos en el sistema.');
      return;
    }

    // Desvinculaciones pendientes de inactivar
    const pendRes = await rrhh.query(`
      SELECT id, empleado_id, tipo, fecha_efectiva::text, empleado_nombre
      FROM desvinculaciones
      WHERE fecha_efectiva <= $1
        AND empleado_id = ANY($2)
        AND (estado IS NULL OR estado NOT IN ('pendiente_gerencia_ops', 'retractada'))
      ORDER BY fecha_efectiva
    `, [hoy, activosIds]);

    if (pendRes.rows.length === 0) {
      console.log('No hay empleados pendientes de inactivar.');
      return;
    }

    console.log(`Empleados a inactivar: ${pendRes.rows.length}\n`);

    for (const dv of pendRes.rows) {
      const label = dv.empleado_nombre ?? `ID #${dv.empleado_id}`;
      console.log(`  → ${label} (${dv.tipo}) — fecha efectiva: ${dv.fecha_efectiva}`);

      if (DRY_RUN) continue;

      const empRes = await core.query('SELECT plaza_id FROM empleados WHERE id = $1', [dv.empleado_id]);
      const plazaId = empRes.rows[0]?.plaza_id ?? null;
      const now = new Date().toISOString();

      await core.query(`
        UPDATE empleados
        SET activo = false, plaza_id = NULL,
            aud_usuario = 'sistema:inactivar-desvinculados', updated_at = $1
        WHERE id = $2
      `, [now, dv.empleado_id]);

      await rrhh.query(`
        UPDATE empleados
        SET activo = false, plaza_id = NULL,
            aud_usuario = 'sistema:inactivar-desvinculados', updated_at = $1
        WHERE id = $2
      `, [now, dv.empleado_id]);

      if (plazaId) {
        const otrosRes = await core.query(
          'SELECT COUNT(*)::int AS cnt FROM empleados WHERE plaza_id = $1 AND activo = true',
          [plazaId]
        );
        if (otrosRes.rows[0].cnt === 0) {
          await core.query(
            "UPDATE plazas SET estado = 'vacante', updated_at = $1 WHERE id = $2",
            [now, plazaId]
          );
          const motivo = dv.tipo === 'despido' ? 'despido' : 'renuncia';
          await core.query(`
            UPDATE plaza_historial
            SET fecha_fin = $1, motivo_salida = $2,
                aud_usuario = 'sistema:inactivar-desvinculados'
            WHERE plaza_id = $3 AND fecha_fin IS NULL
          `, [dv.fecha_efectiva, motivo, plazaId]);
          console.log(`    Plaza liberada (id=${plazaId}) → vacante + historial cerrado`);
        }
      }
    }

    if (DRY_RUN) {
      console.log('\n⚠ Modo --dry-run: no se realizó ningún cambio.');
    } else {
      console.log(`\nSe inactivaron ${pendRes.rows.length} empleado(s).`);
    }

  } finally {
    await core.end().catch(() => {});
    await rrhh.end().catch(() => {});
  }
}

main().catch(err => { console.error(err); process.exit(1); });
