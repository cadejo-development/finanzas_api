/**
 * _dbLogger.js
 *
 * Helper de logging a rrhh_db.error_logs para scripts de sync.
 * Permite que errores y warnings del sync aparezcan en el Monitor de Errores
 * del sistema RRHH, visible para el equipo GEN_INF.
 *
 * Uso:
 *   const { createLogger } = require('./_dbLogger');
 *   const dbLog = createLogger('sync_empleados_to_rds.js');
 *
 *   await dbLog.error('syncExpedientes', err);
 *   await dbLog.warning('ingresos_personal', 'No se crearon registros', { detalle: '...' });
 *   await dbLog.end(); // al terminar el script
 */

require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const { Pool } = require('pg');

function createLogger(scriptName) {
  let pool = null;
  let client = null;

  async function getClient() {
    if (client) return client;
    pool = new Pool({
      host:     process.env.DB_HOST,
      port:     5432,
      database: 'rrhh_db',
      user:     process.env.DB_USERNAME,
      password: process.env.DB_PASSWORD,
      ssl:      { rejectUnauthorized: false },
      connectionTimeoutMillis: 10000,
    });
    client = await pool.connect();
    return client;
  }

  async function insert(severidad, funcion, mensaje, trace, extraData) {
    try {
      const c = await getClient();
      await c.query(
        `INSERT INTO error_logs
           (sistema, controlador, funcion, metodo_http, url,
            mensaje, trace, request_data,
            severidad, resuelto, created_at, updated_at)
         VALUES ($1,$2,$3,'CRON','github-actions',$4,$5,$6,$7,false,NOW(),NOW())`,
        [
          'SYNC',
          scriptName,
          funcion,
          String(mensaje).slice(0, 5000),
          trace ? String(trace).slice(0, 10000) : null,
          extraData ? JSON.stringify(extraData) : null,
          severidad,
        ]
      );
    } catch (logErr) {
      // El logger nunca debe crashear el script principal
      console.error(`[_dbLogger] No se pudo escribir en error_logs: ${logErr.message}`);
    }
  }

  return {
    /**
     * Loguea un Error (o cualquier objeto con .message/.stack) como severidad 'error'.
     * @param {string} funcion  Nombre de la función/bloque donde ocurrió
     * @param {Error}  err      El error capturado
     * @param {object} [extra]  Contexto adicional (IDs, parámetros, etc.)
     */
    async error(funcion, err, extra) {
      const msg = err instanceof Error ? err.message : String(err);
      const stack = err instanceof Error ? err.stack : null;
      console.error(`[${scriptName}][ERROR] ${funcion}: ${msg}`);
      await insert('error', funcion, msg, stack, extra);
    },

    /**
     * Loguea un warning (situación inesperada pero no fatal).
     * @param {string} funcion  Nombre de la función/bloque
     * @param {string} msg      Descripción del warning
     * @param {object} [extra]  Contexto adicional
     */
    async warning(funcion, msg, extra) {
      console.warn(`[${scriptName}][WARN ] ${funcion}: ${msg}`);
      await insert('warning', funcion, msg, null, extra);
    },

    /** Cierra la conexión del logger al terminar el script. */
    async end() {
      try {
        if (client) { client.release(); client = null; }
        if (pool)   { await pool.end(); pool = null; }
      } catch (_) {}
    },
  };
}

module.exports = { createLogger };
