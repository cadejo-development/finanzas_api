/**
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

 * check_botones_pos.js
 * Verifica si Polka, Sammy y Lupe Reyes Draft aparecen
 * como botones activos en los formularios POS de SQL Server.
 */

const sql = require('mssql');

const sqlConfig = {
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_USERNAME_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 15000 },
};

const TERMINOS = ['POLKA', 'SAMMY', 'LUPE REYES'];

async function main() {
  const pool = await sql.connect(sqlConfig);

  const whereProd = TERMINOS
    .map(t => `PRO.proNombre LIKE '%${t}%'`)
    .join(' OR ');

  // ── 1. Botones en FormulariosGraficosXProductos ────────────────────────────
  const q1 = `
    SELECT
      PRO.proCodigo       AS codigo,
      PRO.proNombre       AS producto,
      PRO.proActivo       AS prod_activo,
      FRM.mfrmgCodigo     AS frm_codigo,
      FRM.mfrmgNombre     AS frm_nombre,
      FRM.mfrmgActivo     AS frm_activo,
      FXPR.frmgxprdEliminado AS boton_eliminado
    FROM olComun.dbo.FormulariosGraficosXProductos FXPR WITH (NOLOCK)
    INNER JOIN olComun.dbo.Productos PRO WITH (NOLOCK)
           ON PRO.proId = FXPR.proId
    INNER JOIN olComun.dbo.maeFormulariosGraficos FRM WITH (NOLOCK)
           ON FRM.mfrmgId = FXPR.mfrmgId
    WHERE (${whereProd})
    ORDER BY PRO.proNombre, FRM.mfrmgNombre
  `;

  const rows1 = (await pool.request().query(q1)).recordset;

  console.log('=== POLKA / SAMMY / LUPE REYES — BOTONES EN FORMULARIOS POS ===\n');
  if (rows1.length === 0) {
    console.log('No aparecen en ningún formulario gráfico POS.\n');
  } else {
    const col = (s, n) => String(s ?? '').slice(0, n).padEnd(n);
    const bool = b => b ? '  SI  ' : '  NO  ';

    console.log('CÓDIGO         | PRODUCTO                              | P_ACT | FORMULARIO                    | F_ACT | BOT_ELIM');
    console.log('───────────────|───────────────────────────────────────|───────|───────────────────────────────|───────|─────────');
    rows1.forEach(r => {
      console.log(
        `${col(r.codigo, 14)} | ${col(r.producto, 38)} | ${bool(r.prod_activo)} | ${col(r.frm_nombre, 30)} | ${bool(r.frm_activo)} | ${bool(!r.boton_eliminado)}`
      );
    });
  }

  // ── 2. Resumen: cuántos están activos como botón ───────────────────────────
  const activosComoBoton = rows1.filter(r => !r.boton_eliminado && r.frm_activo && r.prod_activo);
  console.log(`\n→ Total filas en formularios: ${rows1.length}`);
  console.log(`→ Con botón activo (prod_activo=SI, frm_activo=SI, boton_eliminado=NO): ${activosComoBoton.length}`);

  if (activosComoBoton.length > 0) {
    const unicos = [...new Set(activosComoBoton.map(r => r.producto))];
    console.log('\nProductos únicos activos como botón POS:');
    unicos.forEach(n => console.log('  ⚠️ ', n));
  }

  // ── 3. También verificar por categorías en formularios ─────────────────────
  const whereCat = TERMINOS
    .map(t => `CPR.cprNombre LIKE '%${t}%'`)
    .join(' OR ');

  const q2 = `
    SELECT
      CPR.cprNombre       AS categoria,
      FRM.mfrmgNombre     AS frm_nombre,
      FRM.mfrmgActivo     AS frm_activo
    FROM olComun.dbo.FormulariosGraficosXCatProductos FXCAT WITH (NOLOCK)
    INNER JOIN olComun.dbo.CategoriasProductos CPR WITH (NOLOCK)
           ON CPR.cprId = FXCAT.cprId
    INNER JOIN olComun.dbo.maeFormulariosGraficos FRM WITH (NOLOCK)
           ON FRM.mfrmgId = FXCAT.mfrmgId
    WHERE (${whereCat})
    ORDER BY CPR.cprNombre, FRM.mfrmgNombre
  `;

  const rows2 = (await pool.request().query(q2)).recordset;
  console.log(`\n=== CATEGORÍAS QUE CONTIENEN ESOS TÉRMINOS EN FORMULARIOS ===`);
  if (rows2.length === 0) {
    console.log('Ninguna categoría con esos nombres en formularios POS.');
  } else {
    rows2.forEach(r => console.log(`  Categoría "${r.categoria}" → formulario "${r.frm_nombre}" (activo: ${r.frm_activo ? 'SI' : 'NO'})`));
  }

  await pool.close();
}

main().catch(e => { console.error(e.message); process.exit(1); });
