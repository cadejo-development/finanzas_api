/**
 * remigrate_proveedores.js
 *
 * Limpia la tabla proveedores en PostgreSQL (pagos) y la re-importa
 * completa desde SQL Server (olcomun), incluyendo tipo_contribuyente.
 *
 * Mapeo prvContribuyente:
 *   0    → no_inscrito       (Factura consumidor final, sin NRC)
 *   1    → contribuyente     (Inscrito IVA, CCF, tiene NRC)
 *   2    → gran_contribuyente (Régimen especial DGII)
 *   null → null              (sin clasificar)
 *
 * REQUIERE: VPN activa hacia 10.0.4.20
 * Ejecutar: node remigrate_proveedores.js
 */

const sql  = require('mssql');
const { Client } = require('pg');

const MSSQL_CFG = {
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  user: 'olimporeader', password: 'olimporeader',
  options: { encrypt: false, trustServerCertificate: true },
};

const PG_CFG = {
  host: 'crossover.proxy.rlwy.net',
  port: 18406,
  database: 'railway',
  user: 'postgres',
  password: 'ojYHrFKtPwXxBPbjGsheJMncJIgUgykY',
  ssl: { rejectUnauthorized: false },
};

// Mapeo numérico de SQL Server → codigo en contribuyentes
const CONTRIB_MAP = {
  0: 'no_inscrito',
  1: 'contribuyente',
  2: 'gran_contribuyente',
};

async function run() {
  console.log('Conectando a SQL Server y PostgreSQL...');
  const [pool, pg] = await Promise.all([
    sql.connect(MSSQL_CFG),
    (async () => { const c = new Client(PG_CFG); await c.connect(); return c; })(),
  ]);
  console.log('Conectado.\n');

  try {
    // ── 1. Sincronizar catálogo contribuyentes ─────────────────────────────
    console.log('Sincronizando catálogo de contribuyentes...');
    const catalogContribs = [
      { codigo: 'no_inscrito',       nombre: 'No Inscrito / Factura Consumidor Final' },
      { codigo: 'contribuyente',     nombre: 'Contribuyente Inscrito en IVA' },
      { codigo: 'gran_contribuyente',nombre: 'Gran Contribuyente' },
    ];
    for (const c of catalogContribs) {
      await pg.query(
        `INSERT INTO contribuyentes (codigo, nombre, activo, aud_usuario, created_at, updated_at)
         VALUES ($1, $2, true, 'remigrate', now(), now())
         ON CONFLICT (codigo) DO UPDATE SET nombre = EXCLUDED.nombre, updated_at = now()`,
        [c.codigo, c.nombre]
      );
    }

    // ── 2. Leer IDs de contribuyentes en Postgres ──────────────────────────
    const { rows: contribs } = await pg.query(
      `SELECT id, codigo FROM contribuyentes WHERE activo = true`
    );
    const contribIds = Object.fromEntries(contribs.map(c => [c.codigo, c.id]));
    console.log('Contribuyentes disponibles:', contribIds);

    // ── 2. Leer IDs de tipos_persona en Postgres ───────────────────────────
    const { rows: tiposP } = await pg.query(
      `SELECT id, codigo FROM tipos_persona`
    );
    const tipoPersonaIds = Object.fromEntries(tiposP.map(t => [t.codigo, t.id]));
    console.log('Tipos persona disponibles:', tipoPersonaIds);

    // ── 3. Cargar proveedores desde SQL Server ─────────────────────────────
    console.log('\nCargando proveedores desde SQL Server...');
    const result = await pool.query(`
      SELECT
        p.prvCodigo        AS codigo,
        p.prvNombre        AS nombre,
        p.prvNit           AS nit,
        p.prvRegistroIva   AS nrc,
        p.prvTelefono      AS telefono,
        p.prvDireccion     AS direccion,
        p.prvCuentaBanco   AS cuenta_bancaria,
        tc.tcuNombre       AS tipo_cuenta,
        b.bcoNombre        AS banco,
        p.prvEmail         AS correo,
        p.prvPersonaJuridica AS persona_juridica,
        p.prvContribuyente AS contribuyente
      FROM Proveedores p
      LEFT JOIN CuentasBancoXProveedor cbx ON cbx.prvId = p.prvId
      LEFT JOIN TiposCuenta tc ON cbx.tcuId = tc.tcuId
      LEFT JOIN Bancos b ON cbx.bcoId = b.bcoId
      WHERE p.prvActivo = 1
    `);
    const proveedores = result.recordset;
    console.log(`${proveedores.length} proveedores encontrados en SQL Server.`);

    // ── 5. Upsert en lotes de 200 (sin borrar — hay FK con solicitudes_pago) ─
    const COLS = [
      'codigo', 'nombre', 'nit', 'nrc', 'telefono', 'direccion',
      'cuenta_bancaria', 'tipo_cuenta', 'banco', 'correo',
      'tipo_persona_id', 'tipo_contribuyente_id', 'activo',
      'aud_usuario', 'created_at', 'updated_at',
    ];

    const now = new Date().toISOString();
    let inserted = 0;
    const BATCH = 200;

    for (let i = 0; i < proveedores.length; i += BATCH) {
      const batch = proveedores.slice(i, i + BATCH);
      const values = [];
      const params = [];
      let p = 1;

      for (const prov of batch) {
        // tipo_persona: 1=JUR (juridica), null/0=NAT (natural)
        const tipoPersonaCodigo = prov.persona_juridica ? 'JUR' : 'NAT';
        const tipoPersonaId = tipoPersonaIds[tipoPersonaCodigo] ?? null;

        // tipo_contribuyente
        const contribCodigo = prov.contribuyente !== null
          ? CONTRIB_MAP[prov.contribuyente] ?? null
          : null;
        const tipoContribId = contribCodigo ? (contribIds[contribCodigo] ?? null) : null;

        values.push(`(
          $${p++}, $${p++}, $${p++}, $${p++}, $${p++}, $${p++},
          $${p++}, $${p++}, $${p++}, $${p++},
          $${p++}, $${p++}, $${p++},
          $${p++}, $${p++}, $${p++}
        )`);

        params.push(
          (prov.codigo ?? '').trim(),
          (prov.nombre ?? '').trim(),
          prov.nit   ? prov.nit.trim()   : null,
          prov.nrc   ? prov.nrc.trim()   : null,
          prov.telefono  ? prov.telefono.trim()  : null,
          prov.direccion ? prov.direccion.trim() : null,
          prov.cuenta_bancaria ? prov.cuenta_bancaria.trim() : null,
          prov.tipo_cuenta     ? prov.tipo_cuenta.trim()     : null,
          prov.banco           ? prov.banco.trim()           : null,
          prov.correo          ? prov.correo.trim()          : null,
          tipoPersonaId,
          tipoContribId,
          true,
          'remigrate_proveedores',
          now, now,
        );
      }

      const q = `INSERT INTO proveedores (${COLS.join(', ')}) VALUES ${values.join(', ')}
        ON CONFLICT (codigo) DO UPDATE SET
          nombre               = EXCLUDED.nombre,
          nit                  = EXCLUDED.nit,
          nrc                  = EXCLUDED.nrc,
          telefono             = EXCLUDED.telefono,
          direccion            = EXCLUDED.direccion,
          cuenta_bancaria      = EXCLUDED.cuenta_bancaria,
          tipo_cuenta          = EXCLUDED.tipo_cuenta,
          banco                = EXCLUDED.banco,
          correo               = EXCLUDED.correo,
          tipo_persona_id      = EXCLUDED.tipo_persona_id,
          tipo_contribuyente_id = EXCLUDED.tipo_contribuyente_id,
          updated_at           = EXCLUDED.updated_at`;

      await pg.query(q, params);
      inserted += batch.length;
      process.stdout.write(`\r  Insertados: ${inserted}/${proveedores.length}`);
    }

    console.log('\n');

    // ── 6. Resumen ─────────────────────────────────────────────────────────
    const { rows: [{ total }] } = await pg.query(`SELECT COUNT(*) as total FROM proveedores`);
    const { rows: resumen } = await pg.query(`
      SELECT c.codigo, c.nombre, COUNT(pv.id) as total
      FROM contribuyentes c
      LEFT JOIN proveedores pv ON pv.tipo_contribuyente_id = c.id
      GROUP BY c.id, c.codigo, c.nombre
      ORDER BY c.id
    `);

    console.log(`✅ Total proveedores en PostgreSQL: ${total}`);
    console.log('\nDistribución por tipo contribuyente:');
    resumen.forEach(r => console.log(`  ${r.codigo.padEnd(22)}: ${r.total}`));

    const { rows: [{ sin_tipo }] } = await pg.query(
      `SELECT COUNT(*) as sin_tipo FROM proveedores WHERE tipo_contribuyente_id IS NULL`
    );
    console.log(`  sin_clasificar         : ${sin_tipo}`);

  } finally {
    await pool.close();
    await pg.end();
    console.log('\nConexiones cerradas.');
  }
}

run().catch(e => { console.error('\n❌ Error:', e.message); process.exit(1); });
