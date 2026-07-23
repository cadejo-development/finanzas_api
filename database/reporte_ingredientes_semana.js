require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

/**
 * reporte_ingredientes_semana.js
 *
 * Genera un Excel con explosión completa de sub-recetas hasta materia prima.
 *
 * Uso:
 *   node database/reporte_ingredientes_semana.js
 *   node database/reporte_ingredientes_semana.js --desde 2026-05-05 --hasta 2026-05-13
 *   node database/reporte_ingredientes_semana.js --codigos path/to/codigos.csv
 *
 * El CSV debe tener una columna "Cod Pad" con los códigos a incluir.
 * Si no se pasa --codigos, incluye todos los platos/sub-recetas del período.
 */

const { Pool }  = require('pg');
const ExcelJS   = require('exceljs');
const path      = require('path');
const fs        = require('fs');

// ── Args ──────────────────────────────────────────────────────────────────────
function argVal(flag) {
  const i = process.argv.indexOf(flag);
  return i !== -1 ? process.argv[i + 1] : null;
}
const DESDE = argVal('--desde') || '2026-05-05';
const HASTA = argVal('--hasta') || '2026-05-13';

// ── Leer CSV de códigos ───────────────────────────────────────────────────────
function leerCodigos(csvPath) {
  if (!csvPath) return null;
  const abs = path.isAbsolute(csvPath) ? csvPath : path.resolve(process.cwd(), csvPath);
  if (!fs.existsSync(abs)) { console.error(`ERROR: No existe: ${abs}`); process.exit(1); }
  const lines = fs.readFileSync(abs, 'utf8').split(/\r?\n/);
  const header = lines[0].split(',').map(c => c.trim().replace(/^"|"+$/g, ''));
  const colIdx = header.findIndex(h => ['Cod Pad','cod_pad','codigo','Codigo'].includes(h));
  if (colIdx === -1) return lines.slice(1).map(l => l.trim().replace(/^"|"$/g, '')).filter(Boolean);
  return lines.slice(1).map(l => (l.split(',')[colIdx] || '').trim().replace(/^"|"$/g, '')).filter(Boolean);
}
const CODIGOS_FILTRO = leerCodigos(argVal('--codigos'));

// ── DB ────────────────────────────────────────────────────────────────────────
const pgConfig = {
  host: process.env.DB_HOST,
  port: 5432, database: 'compras_db',
  user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
};
const CATS_PLATOS = ['platos_fuertes', 'entradas', 'postres', 'desayunos'];

// ── Tablas de conversión de unidades ─────────────────────────────────────────
// UNIT_CONV[rendUnidad_normalizada][usoUnidad_normalizada] = factor
// Interpretación: 1 rendUnidad = factor usoUnidad
// Se usa: newFactor = factorParent * uso / (rendRaw * UNIT_CONV[rn][un])
const UNIT_CONV = {
  // Peso
  'kg':    { 'oz': 35.27396, 'lb': 2.20462,  'g': 1000       },
  'lb':    { 'oz': 16,       'kg': 0.453592,  'g': 453.592    },
  'oz':    { 'lb': 0.0625,   'kg': 0.0283495, 'g': 28.3495    },
  'g':     { 'oz': 0.035274, 'kg': 0.001,     'lb': 0.0022046 },
  // Volumen
  'lt':    { 'oz_fl': 33.814, 'ml': 1000       },
  'oz_fl': { 'lt': 0.0295735, 'ml': 29.5735    },
  'ml':    { 'oz_fl': 0.033814, 'lt': 0.001    },
};

function normUnit(u) {
  if (!u) return '';
  const s = u.toLowerCase().trim();
  if (s === 'oz')                                          return 'oz';
  if (s === 'oz fl' || s === 'fl oz' || s === 'oz_fl')    return 'oz_fl';
  if (s === 'lt' || s === 'lts' || s === 'litro' || s === 'litros') return 'lt';
  if (s === 'lb' || s === 'lbs')                          return 'lb';
  if (s === 'porcion' || s === 'porciones' || s === 'porción') return 'porcion';
  if (s === 'kg' || s === 'kgs')                          return 'kg';
  if (s === 'u' || s === 'unidad' || s === 'unidades')    return 'u';
  if (s === 'ml')                                          return 'ml';
  if (s === 'g' || s === 'gr' || s === 'gramo' || s === 'gramos') return 'g';
  return s;
}

/**
 * Calcula el factor acumulado para los ingredientes de una sub-receta.
 *   factorParent : factor heredado del nivel anterior
 *   uso          : cantidad_por_plato del ingrediente (cuánto usa el padre)
 *   usoUnidad    : unidad de esa cantidad
 *   rendRaw      : rendimiento de la sub-receta tal como está en la DB
 *   rendUnidad   : unidad del rendimiento
 *
 * Conversiones aplicadas (1 rendUnidad = N usoUnidad):
 *   kg  → oz     : × 35.274   (peso)
 *   kg  → lb     : × 2.205
 *   lt  → oz fl  : × 33.814   (volumen) ← CORRECCIÓN PRINCIPAL
 *   lb  → oz     : × 16
 *   etc. (ver UNIT_CONV)
 */
// Grupos de unidades físicas (peso y volumen son incompatibles entre sí)
const PESO   = new Set(['kg', 'lb', 'oz', 'g']);
const VOLUMEN = new Set(['lt', 'oz_fl', 'ml']);

function calcFactor(factorParent, uso, usoUnidad, rendRaw, rendUnidad) {
  const rn = normUnit(rendUnidad);
  const un = normUnit(usoUnidad);

  // Sin rendUnidad definida o misma unidad normalizada: ratio directo
  if (!rn || rn === un) return factorParent * uso / rendRaw;

  // Conversión cruzada conocida (kg→oz, lt→oz_fl, lb→oz, etc.)
  if (UNIT_CONV[rn] && UNIT_CONV[rn][un] !== undefined) {
    return factorParent * uso / (rendRaw * UNIT_CONV[rn][un]);
  }

  // Una de las unidades es de "conteo" (porcion, u) o combinaciones sin conversión numérica
  // (ej. rend=porcion con ingredientes en oz/lt — normal en cocina: N oz para M porciones)
  // → ratio directo: uso/rendRaw = cantidad del ingrediente por unidad de rendimiento
  const rnFisica = PESO.has(rn) || VOLUMEN.has(rn);
  const unFisica = PESO.has(un) || VOLUMEN.has(un);

  if (rnFisica && unFisica && PESO.has(rn) !== PESO.has(un)) {
    // Genuinamente incompatible: peso vs volumen (oz peso ↔ lt/ml/oz fl)
    // No hay conversión sin conocer la densidad del ingrediente
    console.log(`  ⚠ Peso↔Volumen incompatible: rend="${rendUnidad}" ↔ uso="${usoUnidad}" — usando factorParent/rendRaw`);
    return factorParent / rendRaw;
  }

  // Cualquier otro caso (porcion↔oz, kg↔u, lb↔porcion, etc.): ratio directo
  return factorParent * uso / rendRaw;
}

// ── Carga BFS del árbol de recetas ────────────────────────────────────────────
async function loadAllRecetaTrees(pool, rootRecetaIds) {
  const allRecetas    = {};  // id → receta row
  const allIngredients = {}; // receta_id → [ingredient rows]
  const visited       = new Set();
  let toVisit         = [...rootRecetaIds.map(Number)];

  while (toVisit.length > 0) {
    const batch = toVisit.filter(id => !visited.has(id));
    if (!batch.length) break;
    batch.forEach(id => visited.add(id));

    const rRes = await pool.query(
      'SELECT id, nombre, codigo_origen, rendimiento, rendimiento_unidad FROM recetas WHERE id = ANY($1)',
      [batch]
    );
    for (const r of rRes.rows) allRecetas[r.id] = r;

    const iRes = await pool.query(`
      SELECT ri.receta_id, ri.cantidad_por_plato, ri.unidad, ri.producto_id, ri.sub_receta_id,
             COALESCE(p.nombre,  sr.nombre)        AS ing_nombre,
             COALESCE(p.codigo,  sr.codigo_origen) AS ing_codigo
      FROM receta_ingredientes ri
      LEFT JOIN productos p  ON p.id  = ri.producto_id
      LEFT JOIN recetas    sr ON sr.id = ri.sub_receta_id
      WHERE ri.receta_id = ANY($1)
      ORDER BY ri.receta_id, ri.id
    `, [batch]);

    toVisit = [];
    for (const row of iRes.rows) {
      const rid = row.receta_id;
      if (!allIngredients[rid]) allIngredients[rid] = [];
      allIngredients[rid].push(row);
      if (row.sub_receta_id && !visited.has(Number(row.sub_receta_id))) {
        toVisit.push(Number(row.sub_receta_id));
      }
    }
  }
  return { allRecetas, allIngredients };
}

/**
 * Explode recursivo: devuelve array plano de MP con su factor acumulado.
 * @param {number}  recetaId      - ID de la receta a explotar
 * @param {number}  factorParent  - factor acumulado del nivel anterior
 * @param {object}  allIngredients
 * @param {object}  allRecetas
 * @param {string}  origenPath    - texto descriptivo del camino (para mostrar en Excel)
 * @param {number}  depth         - profundidad actual (seguridad)
 */
function explode(recetaId, factorParent, allIngredients, allRecetas, origenPath, depth = 0) {
  if (depth > 6) return [];
  const ings = allIngredients[recetaId] || [];
  const result = [];

  for (const ing of ings) {
    const uso = parseFloat(ing.cantidad_por_plato) || 0;

    if (ing.sub_receta_id) {
      const sr = allRecetas[ing.sub_receta_id];
      if (!sr) {
        // No se pudo cargar la sub-receta: mostrar como opaca
        result.push({
          origen:        origenPath || 'Directo',
          codigo:        ing.ing_codigo || '—',
          ingrediente:   ing.ing_nombre || '—',
          unidad:        ing.unidad || '',
          cant_en_sub:   uso,
          factor:        factorParent,
          cant_por_plato: uso * factorParent,
          es_opaco:      true,
        });
        continue;
      }
      const rend    = parseFloat(sr.rendimiento) || 1;
      const newFactor = calcFactor(factorParent, uso, ing.unidad, rend, sr.rendimiento_unidad);
      const subLabel  = origenPath ? `${origenPath} → ${sr.nombre}` : sr.nombre;
      result.push(...explode(Number(sr.id), newFactor, allIngredients, allRecetas, subLabel, depth + 1));
    } else {
      result.push({
        origen:         origenPath || 'Directo',
        codigo:         ing.ing_codigo || '—',
        ingrediente:    ing.ing_nombre || '—',
        unidad:         ing.unidad || '',
        cant_en_sub:    uso,
        factor:         factorParent,
        cant_por_plato: uso * factorParent,
        es_opaco:       false,
      });
    }
  }
  return result;
}

// ── Estilos Excel ─────────────────────────────────────────────────────────────
const C = {
  NEGRO: 'FF1A1A1A', AMBAR: 'FFFFC72C', GRIS: 'FF2D2D2D', GRIS2: 'FF3A3A3A',
  VERDE: 'FF1E3A2F', VERDE2: 'FF4CAF50', BLANCO: 'FFFFFFFF', ALT: 'FFF5F5F0',
  ROJO: 'FFFF6B6B',
};
const CAT_BG = {
  platos_fuertes: 'FFD4EDDA', entradas: 'FFFEF9C3',
  postres: 'FFFCE4EC', desayunos: 'FFE3F2FD',
};
const CAT_LABEL = {
  platos_fuertes: 'PLATO FUERTE', entradas: 'ENTRADA',
  postres: 'POSTRE', desayunos: 'DESAYUNO',
};

function cs(bg, fg, bold = false, size = 10, wrap = false) {
  return {
    fill:  { type: 'pattern', pattern: 'solid', fgColor: { argb: bg } },
    font:  { color: { argb: fg }, bold, size },
    alignment: { vertical: 'middle', horizontal: 'center', wrapText: wrap },
    border: {
      top: { style: 'thin', color: { argb: 'FF888888' } }, bottom: { style: 'thin', color: { argb: 'FF888888' } },
      left:{ style: 'thin', color: { argb: 'FF888888' } }, right: { style: 'thin', color: { argb: 'FF888888' } },
    },
  };
}
function ap(cell, style) {
  if (style.fill)      cell.fill      = style.fill;
  if (style.font)      cell.font      = style.font;
  if (style.alignment) cell.alignment = style.alignment;
  if (style.border)    cell.border    = style.border;
}

// ── MAIN ──────────────────────────────────────────────────────────────────────
async function main() {
  const pool = new Pool(pgConfig);
  if (CODIGOS_FILTRO) console.log(`Filtrando por ${CODIGOS_FILTRO.length} códigos desde CSV`);
  console.log(`Generando reporte ${DESDE} → ${HASTA} ...`);

  // ── 1. Ventas ──────────────────────────────────────────────────────────────
  let platos;
  const sucursalExpr = `REPLACE(REPLACE(REGEXP_REPLACE(vs.archivo_nombre,'^import_sqlserver_',''),'_2026-[0-9-]+$',''),'_',' ')`;

  if (CODIGOS_FILTRO && CODIGOS_FILTRO.length > 0) {
    const vRes = await pool.query(`
      SELECT vsd.producto_codigo,
             MAX(vsd.producto_nombre)  AS producto_nombre,
             MAX(vsd.categoria_key)    AS categoria_key,
             SUM(vsd.cantidad_vendida) AS total_porciones,
             SUM(vsd.total)            AS total_venta,
             AVG(vsd.precio_unitario)  AS precio_promedio,
             array_agg(DISTINCT ${sucursalExpr} ORDER BY ${sucursalExpr}) AS sucursales
      FROM ventas_semanales_detalle vsd
      JOIN ventas_semanales vs ON vs.id = vsd.venta_semanal_id
      WHERE vs.semana_inicio BETWEEN $1 AND $2
        AND vsd.producto_codigo = ANY($3)
      GROUP BY vsd.producto_codigo
      ORDER BY SUM(vsd.total) DESC
    `, [DESDE, HASTA, CODIGOS_FILTRO]);

    const vm = {};
    for (const r of vRes.rows) vm[r.producto_codigo] = r;

    const sinVentas = CODIGOS_FILTRO.filter(c => !vm[c]);
    const nombresMap = {};
    if (sinVentas.length > 0) {
      const nr = await pool.query(
        'SELECT codigo_origen, nombre, tipo_receta FROM recetas WHERE codigo_origen = ANY($1) AND activa = true',
        [sinVentas]
      );
      for (const r of nr.rows) nombresMap[r.codigo_origen] = r;
      const pr = await pool.query('SELECT codigo, nombre FROM productos WHERE codigo = ANY($1)', [sinVentas]);
      for (const r of pr.rows) if (!nombresMap[r.codigo]) nombresMap[r.codigo] = { nombre: r.nombre };
    }

    platos = CODIGOS_FILTRO.map(cod => vm[cod] || {
      producto_codigo: cod,
      producto_nombre: nombresMap[cod]?.nombre || cod,
      categoria_key:   nombresMap[cod]?.tipo_receta || 'sin_categoria',
      total_porciones: '0', total_venta: '0', precio_promedio: '0', sucursales: [],
    });
    console.log(`  → ${platos.length} recetas (${vRes.rows.length} con ventas en el período)`);
  } else {
    const vRes = await pool.query(`
      SELECT vsd.producto_codigo, vsd.producto_nombre, vsd.categoria_key,
             SUM(vsd.cantidad_vendida) AS total_porciones,
             SUM(vsd.total)            AS total_venta,
             AVG(vsd.precio_unitario)  AS precio_promedio,
             array_agg(DISTINCT ${sucursalExpr} ORDER BY ${sucursalExpr}) AS sucursales
      FROM ventas_semanales_detalle vsd
      JOIN ventas_semanales vs ON vs.id = vsd.venta_semanal_id
      WHERE vs.semana_inicio BETWEEN $1 AND $2
        AND vsd.categoria_key = ANY($3)
      GROUP BY vsd.producto_codigo, vsd.producto_nombre, vsd.categoria_key
      ORDER BY vsd.categoria_key, SUM(vsd.total) DESC
    `, [DESDE, HASTA, CATS_PLATOS]);
    platos = vRes.rows;
    console.log(`  → ${platos.length} platos distintos encontrados`);
  }

  if (!platos.length) { console.log('Sin datos. Saliendo.'); await pool.end(); return; }

  // ── 2. Cargar árboles de recetas (BFS) ─────────────────────────────────────
  const codigos = platos.map(p => p.producto_codigo);
  const rootRecetasRes = await pool.query(
    'SELECT id, nombre, codigo_origen, rendimiento, rendimiento_unidad FROM recetas WHERE codigo_origen = ANY($1) AND activa = true',
    [codigos]
  );
  // Mapear codigo_origen → receta
  const recetaPorCodigo = {};
  for (const r of rootRecetasRes.rows) recetaPorCodigo[r.codigo_origen] = r;

  const rootIds = rootRecetasRes.rows.map(r => Number(r.id));
  console.log(`  → Cargando árboles de ${rootIds.length} recetas...`);
  const { allRecetas, allIngredients } = await loadAllRecetaTrees(pool, rootIds);
  // Agregar las raíces también
  for (const r of rootRecetasRes.rows) allRecetas[r.id] = r;

  // ── 3. Excel ───────────────────────────────────────────────────────────────
  const wb = new ExcelJS.Workbook();
  wb.creator = 'Cadejo Compras'; wb.created = new Date();

  // ── HOJA RESUMEN ───────────────────────────────────────────────────────────
  const wsR = wb.addWorksheet('RESUMEN', { properties: { tabColor: { argb: C.AMBAR } } });
  wsR.views = [{ state: 'frozen', ySplit: 4 }];
  wsR.columns = [
    { width: 5 }, { width: 16 }, { width: 16 }, { width: 44 },
    { width: 14 }, { width: 13 }, { width: 14 }, { width: 42 },
  ];

  wsR.mergeCells('A1:H1');
  ap(wsR.getCell('A1'), cs(C.NEGRO, C.AMBAR, true, 14));
  wsR.getCell('A1').value = `REPORTE DE VENTAS POR PLATO — ${DESDE} al ${HASTA}`;
  wsR.getRow(1).height = 28;

  wsR.mergeCells('A2:H2');
  ap(wsR.getCell('A2'), cs(C.GRIS, 'FFCCCCCC', false, 10));
  wsR.getCell('A2').value = `Cadejo Brewing Company · Ingredientes explotados hasta MP · Generado ${new Date().toLocaleDateString('es-SV')}`;
  wsR.getRow(2).height = 18;

  wsR.addRow([]);

  const hR = wsR.addRow(['#','CATEGORÍA','CÓDIGO','PLATO / SUB-RECETA','PORCIONES\nVENDIDAS','PRECIO PROM.','VENTA TOTAL','SUCURSALES']);
  hR.height = 36;
  hR.eachCell(c => ap(c, cs(C.NEGRO, C.AMBAR, true, 11, true)));

  let grandTotal = 0, grandPorciones = 0, nR = 0;
  for (const p of platos) {
    nR++;
    const bgCat = CAT_BG[p.categoria_key] || C.BLANCO;
    const row = wsR.addRow([
      nR,
      (p.categoria_key || '').replace(/_/g, ' ').toUpperCase(),
      p.producto_codigo,
      p.producto_nombre,
      parseFloat(p.total_porciones),
      parseFloat(p.precio_promedio),
      parseFloat(p.total_venta),
      (p.sucursales || []).join(', '),
    ]);
    row.height = 18;
    row.getCell(5).numFmt = '#,##0.00';
    row.getCell(6).numFmt = '"$"#,##0.00';
    row.getCell(7).numFmt = '"$"#,##0.00';
    [1,2,3,4,5,6,7].forEach(i => ap(row.getCell(i), cs(bgCat, C.NEGRO, false, 10)));
    ap(row.getCell(8), cs(bgCat, 'FF555555', false, 9, true));
    grandTotal     += parseFloat(p.total_venta)     || 0;
    grandPorciones += parseFloat(p.total_porciones) || 0;
  }
  wsR.addRow([]);
  const totR = wsR.addRow(['','','','TOTALES', grandPorciones, '', grandTotal, '']);
  totR.height = 22;
  totR.getCell(5).numFmt = '#,##0.00';
  totR.getCell(7).numFmt = '"$"#,##0.00';
  [4,5,6,7].forEach(i => ap(totR.getCell(i), cs(C.GRIS, C.AMBAR, true, 12)));

  // ── HOJA ESTADÍSTICAS ──────────────────────────────────────────────────────
  const wsE = wb.addWorksheet('ESTADÍSTICAS', { properties: { tabColor: { argb: 'FFFF6B6B' } } });
  wsE.columns = [{ width: 38 }, { width: 20 }, { width: 20 }];
  wsE.mergeCells('A1:C1');
  ap(wsE.getCell('A1'), cs(C.NEGRO, C.AMBAR, true, 15));
  wsE.getCell('A1').value = `ESTADÍSTICAS — ${DESDE} al ${HASTA}`;
  wsE.getRow(1).height = 28;

  const addStatBlock = (title, rows) => {
    wsE.addRow([]);
    const tr = wsE.addRow([title]);
    wsE.mergeCells(`A${tr.number}:C${tr.number}`);
    ap(wsE.getCell(`A${tr.number}`), cs(C.NEGRO, C.AMBAR, true, 13));
    for (const [label, val, fmt] of rows) {
      const r = wsE.addRow([label, val]);
      r.height = 20;
      r.getCell(1).font = { bold: true, size: 11 };
      r.getCell(1).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: C.ALT } };
      if (fmt) r.getCell(2).numFmt = fmt;
      r.getCell(2).alignment = { horizontal: 'right' };
    }
  };

  const porCat = {};
  for (const p of platos) {
    if (!porCat[p.categoria_key]) porCat[p.categoria_key] = { porciones: 0, venta: 0 };
    porCat[p.categoria_key].porciones += parseFloat(p.total_porciones) || 0;
    porCat[p.categoria_key].venta     += parseFloat(p.total_venta)     || 0;
  }
  addStatBlock('RESUMEN GENERAL', [
    ['Total recetas distintas', platos.length, '0'],
    ['Total porciones vendidas', grandPorciones, '#,##0.00'],
    ['Venta total del período',  grandTotal, '"$"#,##0.00'],
    ['Ticket promedio / porción', grandPorciones > 0 ? grandTotal / grandPorciones : 0, '"$"#,##0.00'],
  ]);
  addStatBlock('VENTA POR CATEGORÍA', Object.entries(porCat).map(([cat, d]) => [
    cat.replace(/_/g,' ').toUpperCase(), d.venta, '"$"#,##0.00',
  ]));
  addStatBlock('TOP 5 POR VENTA ($)', [...platos].sort((a,b)=>parseFloat(b.total_venta)-parseFloat(a.total_venta)).slice(0,5).map(p=>[p.producto_nombre.substring(0,35), parseFloat(p.total_venta), '"$"#,##0.00']));
  addStatBlock('TOP 5 POR PORCIONES', [...platos].sort((a,b)=>parseFloat(b.total_porciones)-parseFloat(a.total_porciones)).slice(0,5).map(p=>[p.producto_nombre.substring(0,35), parseFloat(p.total_porciones), '#,##0.00']));

  // ── Acumulador para CONSOLIDADO MP (se construye durante el loop y se escribe al final) ────
  // clave: "codigo||unidad" → { codigo, ingrediente, unidad, total_requerido, platos: Set<nombre> }
  const mpMap = new Map();

  // ── UNA HOJA POR PLATO ─────────────────────────────────────────────────────
  for (const p of platos) {
    const totalVendido = parseFloat(p.total_porciones) || 0;
    const receta = recetaPorCodigo[p.producto_codigo];

    // Explotar ingredientes
    let ingredientesExplotados = [];
    if (receta) {
      ingredientesExplotados = explode(Number(receta.id), 1, allIngredients, allRecetas, '', 0);
    }

    const sheetName = `${p.producto_codigo} ${p.producto_nombre || ''}`
      .replace(/[\\\/\*\?\[\]:]/g, ' ').substring(0, 31);

    const ws = wb.addWorksheet(sheetName, {
      properties: { tabColor: { argb: p.categoria_key === 'postres' ? 'FFFCE4EC' : 'FFE3F2FD' } },
    });
    ws.views = [{ state: 'frozen', ySplit: 6 }];
    ws.columns = [
      { width: 5 }, { width: 36 }, { width: 14 }, { width: 34 },
      { width: 9 }, { width: 12 }, { width: 12 }, { width: 13 },
    ];

    ws.mergeCells('A1:H1');
    ap(ws.getCell('A1'), cs(C.NEGRO, C.AMBAR, true, 16));
    ws.getCell('A1').value = p.producto_nombre;
    ws.getRow(1).height = 28;

    ws.mergeCells('A2:H2');
    ap(ws.getCell('A2'), cs(C.GRIS, 'FFCCCCCC', false, 10));
    ws.getCell('A2').value = `${CAT_LABEL[p.categoria_key] || p.categoria_key}  |  Código: ${p.producto_codigo}  |  ${DESDE} → ${HASTA}`;

    ws.mergeCells('A3:D3');
    ap(ws.getCell('A3'), cs(C.VERDE, C.VERDE2, true, 12));
    ws.getCell('A3').value = `PORCIONES VENDIDAS: ${totalVendido.toLocaleString('es-SV', {minimumFractionDigits:2})}`;
    ws.mergeCells('E3:H3');
    ap(ws.getCell('E3'), cs(C.VERDE, C.VERDE2, true, 12));
    ws.getCell('E3').value = `VENTA TOTAL: $${parseFloat(p.total_venta).toLocaleString('es-SV', {minimumFractionDigits:2})}`;
    ws.getRow(3).height = 22;

    ws.addRow([]); ws.getRow(4).height = 6;

    const iHdr = ws.addRow(['#','SUB-RECETA ORIGEN','CÓD MP','INGREDIENTE MP','UNIDAD','CANT/PLATO','FACTOR','TOTAL REQUERIDO']);
    iHdr.height = 28;
    iHdr.eachCell(c => ap(c, cs(C.GRIS, 'FFDAA520', true, 11)));

    if (!ingredientesExplotados.length) {
      const noRow = ws.addRow(['','','','⚠ Sin ingredientes registrados o receta no configurada','','','','']);
      noRow.getCell(4).font = { italic: true, color: { argb: C.ROJO }, size: 10 };
    } else {
      let iN = 0;
      for (const ing of ingredientesExplotados) {
        iN++;
        const bg       = iN % 2 === 0 ? C.ALT : C.BLANCO;
        const totalReq = ing.cant_por_plato * totalVendido;
        const row = ws.addRow([
          iN,
          ing.origen || 'Directo',
          ing.codigo,
          ing.ingrediente,
          ing.unidad,
          ing.cant_por_plato,
          ing.factor,
          totalReq,
        ]);
        row.height = 17;
        row.getCell(6).numFmt = '0.00000';
        row.getCell(7).numFmt = '0.00000';
        row.getCell(8).numFmt = '0.00000';
        [1,3,4,5,6,7,8].forEach(i => ap(row.getCell(i), cs(bg, C.NEGRO, false, 10)));
        ap(row.getCell(2), cs(ing.origen ? 'FFECE8FF' : bg, '554477', false, 9, true));

        // Acumular para consolidado
        const mpKey = `${ing.codigo}||${ing.unidad}`;
        if (!mpMap.has(mpKey)) {
          mpMap.set(mpKey, { codigo: ing.codigo, ingrediente: ing.ingrediente, unidad: ing.unidad, total_requerido: 0, platos: new Set() });
        }
        const mpEntry = mpMap.get(mpKey);
        mpEntry.total_requerido += totalReq;
        mpEntry.platos.add(p.producto_nombre);
      }
    }

    ws.addRow([]);
    const totI = ws.addRow(['','','','','','MP DISTINTOS:',ingredientesExplotados.length,'TOTAL PLATO →', parseFloat(p.total_venta)]);
    totI.height = 22;
    totI.getCell(8).numFmt = '"$"#,##0.00';
    [6,7,8,9].forEach(i => ap(totI.getCell(i), cs(C.GRIS, C.AMBAR, true, 11)));
  }

  // ── HOJA CONSOLIDADO MP (agregado por ingrediente, una fila por MP) ─────────
  const wsC = wb.addWorksheet('CONSOLIDADO MP', { properties: { tabColor: { argb: C.VERDE2 } } });
  wsC.views = [{ state: 'frozen', ySplit: 4 }];
  wsC.columns = [
    { width: 5 }, { width: 16 }, { width: 40 }, { width: 10 },
    { width: 16 }, { width: 8 }, { width: 70 },
  ];
  wsC.mergeCells('A1:G1');
  ap(wsC.getCell('A1'), cs(C.NEGRO, C.AMBAR, true, 14));
  wsC.getCell('A1').value = `CONSOLIDADO INGREDIENTES (MP explotados) — ${DESDE} al ${HASTA}`;
  wsC.getRow(1).height = 28;
  wsC.mergeCells('A2:G2');
  ap(wsC.getCell('A2'), cs(C.GRIS, 'FFCCCCCC', false, 10));
  wsC.getCell('A2').value = `Total requerido por materia prima sumado de todos los platos · ${mpMap.size} ingredientes distintos`;
  wsC.getRow(2).height = 18;
  wsC.addRow([]);
  const cHdr = wsC.addRow(['#', 'CÓD MP', 'INGREDIENTE MP', 'UNIDAD', 'TOTAL REQUERIDO', '# PLATOS', 'PLATOS QUE LO USAN']);
  cHdr.height = 30;
  cHdr.eachCell(c => ap(c, cs(C.NEGRO, C.AMBAR, true, 11)));

  const mpSorted = [...mpMap.values()].sort((a, b) => a.ingrediente.localeCompare(b.ingrediente, 'es'));
  let cN = 0;
  for (const mp of mpSorted) {
    cN++;
    const bg = cN % 2 === 0 ? C.ALT : C.BLANCO;
    const cRow = wsC.addRow([
      cN,
      mp.codigo,
      mp.ingrediente,
      mp.unidad,
      mp.total_requerido,
      mp.platos.size,
      [...mp.platos].sort().join(', '),
    ]);
    cRow.height = 17;
    cRow.getCell(5).numFmt = '0.000';
    [1, 2, 3, 4, 5, 6].forEach(i => ap(cRow.getCell(i), cs(bg, C.NEGRO, false, 10)));
    ap(cRow.getCell(7), cs(bg, 'FF444444', false, 9, true));
  }

  // Orden de hojas
  wb.getWorksheet('RESUMEN').orderNo      = 1;
  wb.getWorksheet('ESTADÍSTICAS').orderNo  = 2;
  wb.getWorksheet('CONSOLIDADO MP').orderNo = 3;

  // ── Guardar ────────────────────────────────────────────────────────────────
  const sufijo   = CODIGOS_FILTRO ? '_filtrado' : '';
  const fileName = `reporte_ingredientes_${DESDE}_${HASTA}${sufijo}.xlsx`;
  const filePath = path.join(__dirname, '..', fileName);
  await wb.xlsx.writeFile(filePath);

  console.log(`\n✅ Reporte generado: ${filePath}`);
  console.log(`   Recetas: ${platos.length} | Porciones: ${grandPorciones.toFixed(0)} | Total: $${grandTotal.toFixed(2)}`);
  await pool.end();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
