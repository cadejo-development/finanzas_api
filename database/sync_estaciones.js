/**
 * sync_estaciones.js
 *
 * Sincroniza las estaciones de cocina desde SQL Server (olRestaurante.CocinasRst)
 * hacia PostgreSQL (compras_db.estaciones).
 *
 * Uso: node sync_estaciones.js [--dry-run]
 */

const sql      = require('mssql');
const { Pool } = require('pg');

const sqlCfg = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olRestaurante',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 20000 },
};

const pgCfg = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'compras_db', user: 'cadejo_admin', password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false }, keepAlive: true,
};

const DRY_RUN = process.argv.includes('--dry-run');

// Mapeo de palabras clave en nombre de cocina → sucursal_id en core_db
const SUCURSAL_MAP = [
  { keys: ['ZONA ROSA'],       id: 1  },
  { keys: ['SANTA ROSA'],      id: 2  },
  { keys: ['LIBERTAD'],        id: 3  },
  { keys: ['AEROPUERTO 2'],    id: 5  },
  { keys: ['AEROPUERTO'],      id: 4  },
  { keys: ['SAN MIGUEL'],      id: 6  },
  { keys: ['VENECIA', 'PLAZA VENECIA'], id: 7 },
  { keys: ['SANTA ELENA'],     id: 8  },
  { keys: ['HUIZUCAR'],        id: 9  },
  { keys: ['OPICO'],           id: 10 },
  { keys: ['CASA GUIROLA', 'GUIROLA'], id: 11 },
  { keys: ['MALCRIADA'],       id: 16 },
];

function mapSucursal(nombre) {
  const upper = nombre.toUpperCase();
  for (const entry of SUCURSAL_MAP) {
    if (entry.keys.some(k => upper.includes(k))) return entry.id;
  }
  return null;
}

async function main() {
  console.log(`\n=== sync_estaciones.js ${DRY_RUN ? '[DRY-RUN]' : ''} ===\n`);

  const sqlPool = await sql.connect(sqlCfg);
  const pg      = new Pool(pgCfg);

  try {
    // 1. Leer cocinas desde Brilo
    const result = await sqlPool.request().query(`
      SELECT ccirstId, ccirstCodigo, ccirstNombre, ccirstActiva, ccirstEliminado
      FROM   CocinasRst
      WHERE  ccirstEliminado = 0
      ORDER  BY ccirstNombre
    `);

    const cocinas = result.recordset;
    console.log(`Cocinas leídas de Brilo: ${cocinas.length}`);

    let insertadas = 0, actualizadas = 0;

    for (const c of cocinas) {
      const codigoOrigen = String(c.ccirstId);
      const sucursalId   = mapSucursal(c.ccirstNombre);
      const activa       = c.ccirstActiva ? true : false;

      if (DRY_RUN) {
        console.log(`[DRY] ${c.ccirstCodigo} | ${c.ccirstNombre} | sucursal_id=${sucursalId} | activa=${activa}`);
        continue;
      }

      const existing = await pg.query(
        'SELECT id FROM estaciones WHERE codigo_origen = $1',
        [codigoOrigen]
      );

      if (existing.rows.length > 0) {
        await pg.query(
          `UPDATE estaciones
           SET codigo=$1, nombre=$2, activa=$3, sucursal_id=$4, updated_at=NOW()
           WHERE codigo_origen=$5`,
          [c.ccirstCodigo, c.ccirstNombre, activa, sucursalId, codigoOrigen]
        );
        actualizadas++;
      } else {
        await pg.query(
          `INSERT INTO estaciones (codigo, nombre, activa, codigo_origen, sucursal_id)
           VALUES ($1, $2, $3, $4, $5)`,
          [c.ccirstCodigo, c.ccirstNombre, activa, codigoOrigen, sucursalId]
        );
        insertadas++;
      }
    }

    if (!DRY_RUN) {
      console.log(`✔ Insertadas: ${insertadas}`);
      console.log(`✔ Actualizadas: ${actualizadas}`);
      console.log(`✔ Total procesadas: ${cocinas.length}`);
    }

  } finally {
    await pg.end();
    await sql.close();
  }
}

main().catch(err => {
  console.error('ERROR:', err.message);
  process.exit(1);
});
