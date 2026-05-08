/**
 * sync_brilo_flags.js
 *
 * Lee todos los códigos de productos ACTIVOS desde SQL Server (BRILO/olcomun),
 * los guarda en memoria, compara contra las recetas en compras_db (PostgreSQL)
 * y actualiza SOLO el campo sincronizado_brilo:
 *   - true  → el código existe en BRILO (ya estaba allá)
 *   - false → el código NO existe en BRILO (plato nuevo, hay que exportarlo)
 *
 * No toca modificado_localmente. No inserta ni elimina registros.
 *
 * Uso: node database/sync_brilo_flags.js
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const sqlConfig = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

const pgConfig = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'compras_db', user: 'cadejo_admin',
  password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
  connectionTimeoutMillis: 30000,
};

async function main() {
  // ── 1. Conectar ────────────────────────────────────────────────────────────
  console.log('Conectando a SQL Server (BRILO)...');
  const sqlPool = await sql.connect(sqlConfig);
  console.log('SQL Server OK\n');

  const pgPool = new Pool(pgConfig);
  const pg     = await pgPool.connect();
  console.log('PostgreSQL OK\n');

  try {
    // ── 2. Leer TODOS los códigos activos de BRILO (en memoria) ─────────────
    console.log('Leyendo códigos activos de BRILO...');
    const briloRes = await sqlPool.request().query(`
      SELECT TRIM(proCodigo) AS codigo
      FROM   olComun.dbo.Productos WITH (NOLOCK)
      WHERE  proActivo = 1
        AND  proCodigo IS NOT NULL
        AND  LTRIM(RTRIM(proCodigo)) <> ''
    `);

    const codigosBrilo = new Set(
      briloRes.recordset.map(r => (r.codigo || '').trim())
    );
    console.log(`  → ${codigosBrilo.size} códigos activos en BRILO\n`);

    // ── 3. Leer recetas con código_origen de nuestra base ───────────────────
    console.log('Leyendo recetas de compras_db...');
    const pgRes = await pg.query(`
      SELECT id, codigo_origen, sincronizado_brilo
      FROM   recetas
      WHERE  codigo_origen IS NOT NULL
        AND  codigo_origen <> ''
    `);
    const recetas = pgRes.rows;
    console.log(`  → ${recetas.length} recetas con código en nuestra base\n`);

    // ── 4. Comparar y actualizar sincronizado_brilo ──────────────────────────
    let marcadosTrue  = 0;
    let marcadosFalse = 0;
    let sinCambio     = 0;
    const nuevosSinBrilo = [];  // para mostrar cuáles son nuevos

    await pg.query('BEGIN');

    for (const receta of recetas) {
      const codigo       = (receta.codigo_origen || '').trim();
      const existeEnBrilo = codigosBrilo.has(codigo);
      const valorActual   = receta.sincronizado_brilo;

      if (existeEnBrilo === Boolean(valorActual)) {
        sinCambio++;
        continue;
      }

      await pg.query(
        'UPDATE recetas SET sincronizado_brilo = $1 WHERE id = $2',
        [existeEnBrilo, receta.id]
      );

      if (existeEnBrilo) {
        marcadosTrue++;
      } else {
        marcadosFalse++;
        nuevosSinBrilo.push(codigo);
      }
    }

    await pg.query('COMMIT');

    // ── 5. Platos sin código (no comparables) ────────────────────────────────
    const sinCodRes = await pg.query(`
      SELECT COUNT(*) AS cnt
      FROM   recetas
      WHERE  (codigo_origen IS NULL OR codigo_origen = '')
        AND  activa = true
        AND  (tipo_receta = 'plato' OR tipo_receta IS NULL)
    `);
    const sinCodigo = parseInt(sinCodRes.rows[0].cnt, 10);

    // ── 6. Resumen ────────────────────────────────────────────────────────────
    console.log('══════════════════════════════════════════════');
    console.log('RESULTADO:');
    console.log(`  sincronizado_brilo = true  (ya existen en BRILO) : ${marcadosTrue}`);
    console.log(`  sincronizado_brilo = false (NO están en BRILO)   : ${marcadosFalse}`);
    console.log(`  Sin cambio (ya tenían el valor correcto)          : ${sinCambio}`);
    console.log(`  Platos activos sin código (no comparables)        : ${sinCodigo}`);

    if (nuevosSinBrilo.length > 0) {
      console.log('\nCódigos marcados como NUEVOS (no están en BRILO):');
      nuevosSinBrilo.forEach(c => console.log(`  - ${c}`));
    }

    console.log('\n✓ Solo se actualizó sincronizado_brilo. Sin otros cambios.');

  } catch (err) {
    await pg.query('ROLLBACK').catch(() => {});
    console.error('ERROR:', err.message);
    process.exit(1);
  } finally {
    pg.release();
    await pgPool.end();
    await sqlPool.close();
  }
}

main().catch(err => {
  console.error('Error fatal:', err.message);
  process.exit(1);
});
