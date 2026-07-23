/**
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

 * _dry_run_fix_ingredientes.js
 *
 * Compara el estado ACTUAL de receta_ingredientes en RDS vs lo que
 * quedaría después de correr sync_recetas_diario con el fix (mxprCantUnidad).
 *
 * Solo afecta recetas con modificado_localmente = false.
 *
 * Solo procesa recetas Activas (estado_id=4).
 * Borradores, Inactivas y Finalizadas quedan excluidas.
 *
 * Genera: dry_run_fix_ingredientes_YYYY-MM-DD.xlsx
 *   Hoja "Auto-corregibles" — mod_local=false: se arreglan solos al correr el sync
 *   Hoja "Manuales"         — mod_local=true:  el sync NO los toca, hay que corregir a mano
 *   Hoja "Huerfanas"        — ingredientes en RDS que ya no existen en BRILO
 *   Hoja "Resumen"          — totales por receta
 */

const sql      = require('mssql');
const { Client } = require('pg');
const XLSX     = require('xlsx');

const SQL_CFG = {
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_USERNAME_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 20000 },
};
const PG_CFG = {
  host: process.env.DB_HOST, port: 5432,
  database: 'compras_db', user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
};

const UNIT_MAP = {
  'ONZAS': 'oz', 'ONZAS FLUIDAS': 'oz fl', 'UNIDAD': 'u',
  'LIBRA': 'lb', 'LITRO': 'lt', 'KILOGRAMO': 'kg',
  'BARRIL': 'barril', 'PORCION': 'porcion', 'REBANADA': 'rebanada',
};
const normUnit = s => !s ? 'u' : (UNIT_MAP[s.trim().toUpperCase()] ?? s.trim().toLowerCase().slice(0, 20));

const round6 = n => Math.round(n * 1e6) / 1e6;

async function main() {
  console.log('Conectando...');
  const sqlPool = await sql.connect(SQL_CFG);
  const pg = new Client(PG_CFG);
  await pg.connect();
  console.log('Conexiones OK');

  // ── 1. Recetas Activas en RDS (estado_id=4) — separadas por mod_local ────────
  const rdsRec = (await pg.query(`
    SELECT r.id, r.codigo_origen, r.nombre, r.tipo_receta, r.modificado_localmente
    FROM recetas r
    WHERE r.codigo_origen IS NOT NULL
      AND r.codigo_origen != ''
      AND r.estado_id = 4
      AND r.activa = true
    ORDER BY r.codigo_origen
  `)).rows;

  const rdsAutoFix = rdsRec.filter(r => !r.modificado_localmente); // sync arregla automáticamente
  const rdsManuales = rdsRec.filter(r =>  r.modificado_localmente); // sync NO toca, revisar a mano

  console.log(`Recetas Activas total:          ${rdsRec.length}`);
  console.log(`  Auto-corregibles (mod=false): ${rdsAutoFix.length}`);
  console.log(`  Manuales (mod=true):          ${rdsManuales.length}`);

  const rdsMap = {};
  rdsRec.forEach(r => { rdsMap[r.codigo_origen] = r; });
  const codigosRds = rdsRec.map(r => r.codigo_origen);

  // ── 2. Ingredientes actuales en RDS ──────────────────────────────────────────
  const rdsIng = (await pg.query(`
    SELECT ri.receta_id, ri.id as ri_id,
           p.codigo as prod_codigo, p.nombre as prod_nombre,
           ri.cantidad_por_plato, ri.unidad
    FROM receta_ingredientes ri
    JOIN productos p ON p.id = ri.producto_id
    WHERE ri.receta_id IN (
      SELECT id FROM recetas
      WHERE codigo_origen = ANY($1)
        AND modificado_localmente = false
    )
  `, [codigosRds])).rows;

  // Map receta_id → [ ingredientes ]
  const rdsIngMap = {};
  rdsIng.forEach(r => {
    if (!rdsIngMap[r.receta_id]) rdsIngMap[r.receta_id] = [];
    rdsIngMap[r.receta_id].push(r);
  });

  // Mapa codigo_origen → receta_id
  const codigoToId = {};
  rdsRec.forEach(r => { codigoToId[r.codigo_origen] = r.id; });

  // ── 3. Recetas en BRILO ───────────────────────────────────────────────────────
  // Buscar en BRILO en lotes de 500 para no superar el límite de 2100 parámetros
  let briloRec = [];
  const CHUNK = 500;
  for (let ci = 0; ci < codigosRds.length; ci += CHUNK) {
    const chunk = codigosRds.slice(ci, ci + CHUNK);
    const ph    = chunk.map((_, i) => `@c${ci + i}`).join(',');
    const req   = sqlPool.request();
    chunk.forEach((c, i) => req.input(`c${ci + i}`, sql.VarChar, c));
    const rows = (await req.query(`
      SELECT p.proId, TRIM(p.proCodigo) AS codigo, p.proNombre AS nombre
      FROM Productos p
      WHERE TRIM(p.proCodigo) IN (${ph})
    `)).recordset;
    briloRec.push(...rows);
  }
  console.log(`Recetas encontradas en BRILO: ${briloRec.length}`);

  const briloProIdMap = {};
  briloRec.forEach(r => { briloProIdMap[r.codigo] = r.proId; });
  const proIds = briloRec.map(r => r.proId).join(',');

  // ── 4. Ingredientes BRILO con mxprCantUnidad (correcto) ─────────────────────
  let briloIng = [];
  if (proIds) {
    briloIng = (await sqlPool.request().query(`
      SELECT mx.proId AS rec_proId,
             TRIM(mat.proCodigo) AS ing_codigo,
             mat.proNombre AS ing_nombre,
             mx.mxprCantUnidad AS cantidad,
             ISNULL(uni.uniNombre, 'u') AS unidad_nombre
      FROM MaterialesXProducto mx
      JOIN Productos mat ON mat.proId = mx.proIdMaterial
      LEFT JOIN Unidades uni ON uni.uniId = mx.uniId
      WHERE mx.proId IN (${proIds})
        AND mx.mxprActivo = 1 AND mx.mxprEliminado = 0
        AND mx.mxprCantUnidad > 0
        AND mat.proCodigo IS NOT NULL
    `)).recordset;
  }
  console.log(`Líneas de ingredientes BRILO: ${briloIng.length}`);

  // Map proId → [ ingredientes ]
  const briloIngMap = {};
  briloIng.forEach(r => {
    if (!briloIngMap[r.rec_proId]) briloIngMap[r.rec_proId] = [];
    briloIngMap[r.rec_proId].push(r);
  });

  // ── 5. Comparar ──────────────────────────────────────────────────────────────
  const rowsAutoFix   = []; // mod_local=false: cambios que el sync arregla solo
  const rowsManuales  = []; // mod_local=true:  cambios que hay que corregir a mano
  const rowsHuerfanas = [];
  const rowsResumen   = [];

  for (const rec of rdsRec) {
    const recId   = rec.id;
    const proId   = briloProIdMap[rec.codigo_origen];
    const current = rdsIngMap[recId] ?? [];
    const brilo   = proId ? (briloIngMap[proId] ?? []) : [];
    const esManual = rec.modificado_localmente;

    const briloByCode = {};
    brilo.forEach(b => { briloByCode[b.ing_codigo] = b; });

    const currentByCode = {};
    current.forEach(c => { currentByCode[c.prod_codigo] = c; });

    let nCambios = 0, nSinCambio = 0, nHuerfanas = 0;

    for (const b of brilo) {
      const c          = currentByCode[b.ing_codigo];
      const nuevaCant  = round6(parseFloat(b.cantidad) || 0);
      const nuevaUnidad = normUnit(b.unidad_nombre);

      if (!c) {
        if (!esManual) {   // solo reportar NUEVO en auto-corregibles
          rowsAutoFix.push({
            receta_codigo: rec.codigo_origen, receta_nombre: rec.nombre, tipo_receta: rec.tipo_receta,
            ing_codigo: b.ing_codigo, ing_nombre: b.ing_nombre,
            cambio: 'NUEVO', cant_actual: '', unidad_actual: '', cant_nueva: nuevaCant, unidad_nueva: nuevaUnidad,
          });
        }
        nCambios++;
      } else {
        const cantActual  = round6(parseFloat(c.cantidad_por_plato) || 0);
        const unidadActual = c.unidad ?? '';
        const cantCambia  = Math.abs(cantActual - nuevaCant) > 0.000001;
        const unidCambia  = unidadActual !== nuevaUnidad;

        if (cantCambia || unidCambia) {
          const tipoCambio = cantCambia && unidCambia ? 'CANT+UNIDAD' : cantCambia ? 'CANTIDAD' : 'UNIDAD';
          const row = {
            receta_codigo: rec.codigo_origen, receta_nombre: rec.nombre, tipo_receta: rec.tipo_receta,
            ing_codigo: b.ing_codigo, ing_nombre: b.ing_nombre,
            cambio: tipoCambio, cant_actual: cantActual, unidad_actual: unidadActual,
            cant_nueva: nuevaCant, unidad_nueva: nuevaUnidad,
          };
          if (esManual) rowsManuales.push(row);
          else          rowsAutoFix.push(row);
          nCambios++;
        } else {
          nSinCambio++;
        }
      }
    }

    for (const c of current) {
      if (!briloByCode[c.prod_codigo]) {
        rowsHuerfanas.push({
          receta_codigo: rec.codigo_origen, receta_nombre: rec.nombre,
          mod_local: esManual ? 'SÍ' : 'NO',
          ing_codigo: c.prod_codigo, ing_nombre: c.prod_nombre,
          cantidad: round6(parseFloat(c.cantidad_por_plato) || 0), unidad: c.unidad,
          nota: proId ? 'En RDS pero no en BRILO' : 'Receta no encontrada en BRILO',
        });
        nHuerfanas++;
      }
    }

    rowsResumen.push({
      receta_codigo: rec.codigo_origen, receta_nombre: rec.nombre,
      tipo_receta: rec.tipo_receta, mod_local: esManual ? 'SÍ' : 'NO',
      en_brilo: proId ? 'SÍ' : 'NO',
      ingredientes_rds: current.length, ingredientes_brilo: brilo.length,
      cambios: nCambios, sin_cambio: nSinCambio, huerfanas: nHuerfanas,
    });
  }

  // ── 6. Excel ─────────────────────────────────────────────────────────────────
  const HDRS_CAMBIOS = ['receta_codigo','receta_nombre','tipo_receta','ing_codigo','ing_nombre',
                        'cambio','cant_actual','unidad_actual','cant_nueva','unidad_nueva'];
  const HDRS_CAMBIOS_LABEL = ['Código Receta','Nombre Receta','Tipo','Cód. Ingrediente',
                               'Ingrediente','Tipo Cambio','Cant. ACTUAL','Unidad ACTUAL','Cant. NUEVA','Unidad NUEVA'];

  const wb = XLSX.utils.book_new();

  const shAuto = XLSX.utils.json_to_sheet(rowsAutoFix, { header: HDRS_CAMBIOS });
  XLSX.utils.sheet_add_aoa(shAuto, [HDRS_CAMBIOS_LABEL], { origin: 'A1' });
  XLSX.utils.book_append_sheet(wb, shAuto, 'Auto-corregibles');

  const shManuales = XLSX.utils.json_to_sheet(rowsManuales, { header: HDRS_CAMBIOS });
  XLSX.utils.sheet_add_aoa(shManuales, [HDRS_CAMBIOS_LABEL], { origin: 'A1' });
  XLSX.utils.book_append_sheet(wb, shManuales, 'Manuales (mod_local=true)');

  const shHuerfanas = XLSX.utils.json_to_sheet(rowsHuerfanas, {
    header: ['receta_codigo','receta_nombre','mod_local','ing_codigo','ing_nombre','cantidad','unidad','nota'],
  });
  XLSX.utils.sheet_add_aoa(shHuerfanas, [['Código Receta','Nombre Receta','Mod. Local','Cód. Ingrediente','Ingrediente','Cantidad','Unidad','Nota']], { origin: 'A1' });
  XLSX.utils.book_append_sheet(wb, shHuerfanas, 'Huerfanas');

  const shResumen = XLSX.utils.json_to_sheet(rowsResumen, {
    header: ['receta_codigo','receta_nombre','tipo_receta','mod_local','en_brilo',
             'ingredientes_rds','ingredientes_brilo','cambios','sin_cambio','huerfanas'],
  });
  XLSX.utils.sheet_add_aoa(shResumen, [['Código Receta','Nombre Receta','Tipo','Mod. Local','En BRILO',
    'Ingredientes RDS','Ingredientes BRILO','# Cambios','# Sin cambio','# Huérfanas']], { origin: 'A1' });
  XLSX.utils.book_append_sheet(wb, shResumen, 'Resumen');

  const fecha    = new Date().toISOString().slice(0, 10);
  const filename = `database/dry_run_fix_ingredientes_${fecha}.xlsx`;
  XLSX.writeFile(wb, filename);

  const recetasAutoFix = new Set(rowsAutoFix.map(r => r.receta_codigo)).size;
  const recetasManuales = new Set(rowsManuales.map(r => r.receta_codigo)).size;

  console.log('\n════════════════════════════════════════════════');
  console.log('RESUMEN DRY-RUN (solo recetas Activas)');
  console.log('════════════════════════════════════════════════');
  console.log(`Recetas Activas total:              ${rdsRec.length}`);
  console.log(`  mod_local=false (auto-fix):       ${rdsAutoFix.length}`);
  console.log(`  mod_local=true  (manual):         ${rdsManuales.length}`);
  console.log('');
  console.log(`Auto-corregibles — recetas afect.:  ${recetasAutoFix}`);
  console.log(`Auto-corregibles — líneas cambio:   ${rowsAutoFix.length}`);
  console.log('');
  console.log(`Manuales — recetas con diff:        ${recetasManuales}`);
  console.log(`Manuales — líneas con diff:         ${rowsManuales.length}`);
  console.log('');
  console.log(`Huérfanas (solo en RDS):            ${rowsHuerfanas.length}`);
  console.log(`\nArchivo: ${filename}`);
  console.log('════════════════════════════════════════════════');

  await pg.end();
  await sqlPool.close();
}

main().catch(err => {
  console.error('ERROR:', err.message);
  process.exit(1);
});
