/**
 * sync_proveedores.js
 *
 * SQL Server (olcomun) → PostgreSQL pagos_db (Railway)
 *
 * Sincroniza la tabla proveedores:
 *   - INSERT de nuevos (por codigo)
 *   - UPDATE de existentes si algún campo cambió
 *
 * REQUIERE: VPN activa hacia 10.0.4.20
 *
 * Uso:
 *   node sync_proveedores.js            (ejecuta)
 *   node sync_proveedores.js --dry-run  (solo muestra, no modifica)
 */

const sql      = require('mssql');
const { Client } = require('pg');

const DRY_RUN = process.argv.includes('--dry-run');
const BATCH_SIZE = 200;

// ── SQL Server (olcomun) ──────────────────────────────────────────────────────
const MSSQL_CFG = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

// ── PostgreSQL pagos_db ───────────────────────────────────────────────────────
const PG_CFG = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com', port: 5432,
  database: 'pagos_db', user: 'cadejo_admin',
  password: 'Holamundo#3..',
  ssl: { rejectUnauthorized: false },
  connectionTimeoutMillis: 30000,
};

// ── Query SQL Server ──────────────────────────────────────────────────────────
const MSSQL_QUERY = `
SELECT
    p.prvCodigo       AS codigo,
    LTRIM(RTRIM(p.prvNombre))      AS nombre,
    LTRIM(RTRIM(p.prvNit))         AS nit,
    LTRIM(RTRIM(p.prvRegistroIva)) AS nrc,
    LTRIM(RTRIM(p.prvTelefono))    AS telefono,
    LTRIM(RTRIM(p.prvDireccion))   AS direccion,
    LTRIM(RTRIM(p.prvCuentaBanco)) AS cuenta_bancaria,
    LTRIM(RTRIM(tc.tcuNombre))     AS tipo_cuenta,
    LTRIM(RTRIM(b.bcoNombre))      AS banco,
    LTRIM(RTRIM(p.prvEmail))       AS correo,
    CAST(p.prvPersonaJuridica AS INT) AS persona_juridica
FROM Proveedores p WITH (NOLOCK)
LEFT JOIN CuentasBancoXProveedor cbx WITH (NOLOCK) ON cbx.prvId = p.prvId
LEFT JOIN TiposCuenta tc WITH (NOLOCK)              ON cbx.tcuId = tc.tcuId
LEFT JOIN Bancos b WITH (NOLOCK)                    ON cbx.bcoId = b.bcoId
WHERE p.prvActivo = 1
`;

// Construye un INSERT multifila con ON CONFLICT para un batch de filas
// persona_juridica: 1 → tipo_persona_id=2 (Jurídica), 0 → tipo_persona_id=1 (Natural)
const toTipoPersonaId = (val) => (val ? 2 : 1);

function buildBatchUpsert(rows) {
  // 11 columnas de valor por fila
  const params = [];
  const valueClauses = rows.map((r, i) => {
    const base = i * 11;
    params.push(
      r.codigo          || null,
      r.nombre          || null,
      r.nit             || null,
      r.nrc             || null,
      r.telefono        || null,
      r.direccion       || null,
      r.cuenta_bancaria || null,
      r.tipo_cuenta     || null,
      r.banco           || null,
      r.correo          || null,
      toTipoPersonaId(r.persona_juridica),
    );
    const p = (n) => `$${base + n}`;
    return `(${p(1)},${p(2)},${p(3)},${p(4)},${p(5)},${p(6)},${p(7)},${p(8)},${p(9)},${p(10)},${p(11)},true,'sync_olcomun',NOW(),NOW())`;
  });

  const sql = `
INSERT INTO proveedores
    (codigo, nombre, nit, nrc, telefono, direccion,
     cuenta_bancaria, tipo_cuenta, banco, correo, tipo_persona_id, activo, aud_usuario,
     created_at, updated_at)
VALUES ${valueClauses.join(',\n')}
ON CONFLICT (codigo) DO UPDATE SET
    nombre          = EXCLUDED.nombre,
    nit             = EXCLUDED.nit,
    nrc             = EXCLUDED.nrc,
    telefono        = EXCLUDED.telefono,
    direccion       = EXCLUDED.direccion,
    cuenta_bancaria = EXCLUDED.cuenta_bancaria,
    tipo_cuenta     = EXCLUDED.tipo_cuenta,
    banco           = EXCLUDED.banco,
    correo          = EXCLUDED.correo,
    tipo_persona_id = EXCLUDED.tipo_persona_id,
    aud_usuario     = 'sync_olcomun',
    updated_at      = NOW()
WHERE
    proveedores.nombre          IS DISTINCT FROM EXCLUDED.nombre          OR
    proveedores.nit             IS DISTINCT FROM EXCLUDED.nit             OR
    proveedores.nrc             IS DISTINCT FROM EXCLUDED.nrc             OR
    proveedores.telefono        IS DISTINCT FROM EXCLUDED.telefono        OR
    proveedores.direccion       IS DISTINCT FROM EXCLUDED.direccion       OR
    proveedores.cuenta_bancaria IS DISTINCT FROM EXCLUDED.cuenta_bancaria OR
    proveedores.tipo_cuenta     IS DISTINCT FROM EXCLUDED.tipo_cuenta     OR
    proveedores.banco           IS DISTINCT FROM EXCLUDED.banco           OR
    proveedores.correo          IS DISTINCT FROM EXCLUDED.correo          OR
    proveedores.tipo_persona_id IS DISTINCT FROM EXCLUDED.tipo_persona_id
`;
  return { sql, params };
}

async function main() {
  if (DRY_RUN) console.log('⚠️  DRY-RUN activado — no se modificará la base de datos.\n');

  // 1. SQL Server
  console.log('Conectando a SQL Server…');
  const pool = await sql.connect(MSSQL_CFG);
  console.log('Ejecutando query en olcomun…');
  const result = await pool.request().query(MSSQL_QUERY);
  const rows   = result.recordset;
  console.log(`  → ${rows.length} proveedores activos obtenidos.\n`);
  await sql.close();

  if (DRY_RUN) {
    console.log('Preview (primeros 5 registros):');
    rows.slice(0, 5).forEach(r =>
      console.log(`  [${r.codigo}] ${r.nombre} | NIT: ${r.nit} | banco: ${r.banco || '-'}`)
    );
    console.log('\n✔ Dry-run completado.');
    return;
  }

  // 2. PostgreSQL pagos_db
  console.log('Conectando a PostgreSQL pagos_db…');
  const pg = new Client(PG_CFG);
  await pg.connect();
  console.log('Conectado. Procesando en lotes de', BATCH_SIZE, '…\n');

  let totalProcessed = 0, totalErrors = 0;

  for (let i = 0; i < rows.length; i += BATCH_SIZE) {
    const batch = rows.slice(i, i + BATCH_SIZE);
    try {
      const { sql: q, params } = buildBatchUpsert(batch);
      await pg.query(q, params);
      totalProcessed += batch.length;
      process.stdout.write(`\r  Procesados: ${totalProcessed}/${rows.length}`);
    } catch (err) {
      console.error(`\n  ✗ Error en batch ${i}-${i + batch.length}: ${err.message}`);
      totalErrors += batch.length;
    }
  }

  console.log('\n');
  await pg.end();

  console.log('─────────────────────────────────────────');
  console.log('✔ Sincronización completada.');
  console.log(`  Total origen : ${rows.length}`);
  console.log(`  Procesados   : ${totalProcessed}`);
  console.log(`  Errores      : ${totalErrors}`);
  console.log('─────────────────────────────────────────');
}

main().catch(err => {
  console.error('\n✗ Error fatal:', err.message);
  process.exit(1);
});
