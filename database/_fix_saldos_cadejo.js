require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

/**
 * _fix_saldos_cadejo.js
 *
 * Corrige dias_usados en saldos_cadejo basándose en los permisos
 * de tipo 'dias_cadejo' con estado 'aprobado' que ya existen en la BD.
 *
 * El bug: SolicitudEmailController no llamaba SaldoCadejoService::descontar
 * al aprobar por link de email, dejando dias_usados = 0 aunque el permiso
 * ya estaba aprobado.
 *
 * Uso:
 *   node _fix_saldos_cadejo.js             (dry-run, solo muestra)
 *   node _fix_saldos_cadejo.js --apply     (aplica los cambios)
 */

const { Pool } = require('pg');

const PG_CFG = {
  host: process.env.DB_HOST, port: 5432,
  database: 'rrhh_db', user: process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
};

const APPLY = process.argv.includes('--apply');

async function main() {
  const pool = new Pool(PG_CFG);
  const client = await pool.connect();

  try {
    console.log('Conectando a rrhh_db...\n');

    // 1. Obtener el ID del tipo de permiso 'dias_cadejo'
    const tipoRes = await client.query(
      `SELECT id, nombre FROM tipos_permiso WHERE codigo = 'dias_cadejo' LIMIT 1`
    );
    if (!tipoRes.rows.length) {
      console.error('No se encontró tipo_permiso con codigo = "dias_cadejo"');
      return;
    }
    const tipoCadejoId = tipoRes.rows[0].id;
    console.log(`Tipo permiso dias_cadejo: id=${tipoCadejoId} (${tipoRes.rows[0].nombre})\n`);

    // 2. Agrupar todos los permisos aprobados de tipo cadejo por empleado y año
    const permisosRes = await client.query(`
      SELECT
        p.empleado_id,
        EXTRACT(YEAR FROM p.fecha)::int AS anio,
        COALESCE(SUM(p.dias), COUNT(*)) AS dias_usados_real
      FROM permisos p
      WHERE p.tipo_permiso_id = $1
        AND p.estado = 'aprobado'
      GROUP BY p.empleado_id, EXTRACT(YEAR FROM p.fecha)::int
      ORDER BY p.empleado_id, anio
    `, [tipoCadejoId]);

    if (!permisosRes.rows.length) {
      console.log('No hay permisos días cadejo aprobados. Nada que corregir.');
      return;
    }

    console.log(`Empleados con días cadejo aprobados: ${permisosRes.rows.length} combinación(es) empleado-año\n`);

    // 3. Para cada combinación empleado-año, revisar y corregir saldo_cadejo
    let corregidos = 0;
    let sinCambio  = 0;
    let creados    = 0;

    for (const row of permisosRes.rows) {
      const { empleado_id, anio, dias_usados_real } = row;
      const diasReal = parseFloat(dias_usados_real);

      // Buscar registro existente
      const saldoRes = await client.query(
        `SELECT id, dias_disponibles, dias_usados FROM saldos_cadejo
         WHERE empleado_id = $1 AND anio = $2`,
        [empleado_id, anio]
      );

      if (saldoRes.rows.length) {
        const saldo = saldoRes.rows[0];
        const usadosActual = parseFloat(saldo.dias_usados);

        if (Math.abs(usadosActual - diasReal) < 0.01) {
          console.log(`  emp ${empleado_id} año ${anio}: ya correcto (usados=${usadosActual}) ✓`);
          sinCambio++;
        } else {
          console.log(`  emp ${empleado_id} año ${anio}: dias_usados ${usadosActual} → ${diasReal} ${APPLY ? '(APLICANDO)' : '(dry-run)'}`);
          if (APPLY) {
            await client.query(
              `UPDATE saldos_cadejo SET dias_usados = $1 WHERE id = $2`,
              [diasReal, saldo.id]
            );
          }
          corregidos++;
        }
      } else {
        console.log(`  emp ${empleado_id} año ${anio}: sin registro, crear con dias_disponibles=3 dias_usados=${diasReal} ${APPLY ? '(APLICANDO)' : '(dry-run)'}`);
        if (APPLY) {
          await client.query(
            `INSERT INTO saldos_cadejo (empleado_id, anio, dias_disponibles, dias_usados, aud_usuario)
             VALUES ($1, $2, 3, $3, 'fix_saldos_cadejo')`,
            [empleado_id, anio, diasReal]
          );
        }
        creados++;
      }
    }

    console.log(`\n─────────────────────────────────────`);
    console.log(`Sin cambio: ${sinCambio}`);
    console.log(`Corregidos: ${corregidos}`);
    console.log(`Creados:    ${creados}`);
    if (!APPLY && (corregidos + creados) > 0) {
      console.log(`\nEjecuta con --apply para aplicar los cambios.`);
    }
  } finally {
    client.release();
    await pool.end();
  }
}

main().catch(err => { console.error(err); process.exit(1); });
