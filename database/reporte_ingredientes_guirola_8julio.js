/**
 * reporte_ingredientes_guirola_8julio.js
 *
 * Genera un Excel con el consumo de ingredientes para Casa Guirola (Mansión)
 * correspondiente al día 8 de julio de 2026.
 *
 * Las cantidades se expresan en la unidad de compra del producto (unidad base).
 * La explosión de sub-recetas es completa (BFS hasta la materia prima hoja).
 *
 * Uso:
 *   node database/reporte_ingredientes_guirola_8julio.js
 */

'use strict';

const { Pool } = require('pg');
const ExcelJS  = require('exceljs');
const path     = require('path');

// ── Configuración ─────────────────────────────────────────────────────────────
const FECHA         = '2026-07-08';
const SUCURSAL_ID   = 11;   // Restaurante Casa Guirola (Mansión)

const pgConfig = {
  host:     'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port:     5432,
  database: 'compras_db',
  user:     'cadejo_admin',
  password: 'Holamundo#3..',
  ssl:      { rejectUnauthorized: false },
};

// ── Tabla de conversiones estándar: factor para convertir FROM → TO ───────────
const CONV = {
  // Peso
  'oz:lb':   1 / 16,         'lb:oz':   16,
  'oz:kg':   1 / 35.274,     'kg:oz':   35.274,
  'oz:g':    28.3495,         'g:oz':    1 / 28.3495,
  'lb:kg':   0.453592,        'kg:lb':   2.20462,
  'lb:g':    453.592,         'g:lb':    1 / 453.592,
  'g:kg':    0.001,           'kg:g':    1000,
  // Volumen
  'ml:lt':   0.001,           'lt:ml':   1000,
  'ml:l':    0.001,           'l:ml':    1000,
  'lt:l':    1,               'l:lt':    1,
  'oz_fl:lt': 0.0295735,      'lt:oz_fl': 33.814,
  'oz_fl:ml': 29.5735,        'ml:oz_fl': 1 / 29.5735,
  // Oz sin sufijo "fl": en recetas de restaurante refiere siempre a onza líquida
  'oz:lt':   0.0295735,       'lt:oz':   33.814,
  'oz:ml':   29.5735,         'ml:oz':   1 / 29.5735,
  // Conteo: porcion y u son equivalentes (1 porción = 1 unidad)
  'porcion:u': 1,             'u:porcion': 1,
};

function normUnit(u) {
  if (!u) return '';
  const s = u.toLowerCase().trim();
  if (s === 'oz fl' || s === 'fl oz' || s === 'oz_fl') return 'oz_fl';
  if (s === 'lt' || s === 'litro' || s === 'litros' || s === 'l') return 'lt';
  if (s === 'lts')                                                  return 'lt';
  if (s === 'lb' || s === 'lbs')                                    return 'lb';
  if (s === 'kg' || s === 'kgs')                                    return 'kg';
  if (s === 'g'  || s === 'gr' || s === 'gramo' || s === 'gramos') return 'g';
  if (s === 'ml')                                                    return 'ml';
  if (s === 'oz')                                                    return 'oz';
  if (s === 'u'  || s === 'unidad' || s === 'unidades')             return 'u';
  if (s === 'porcion' || s === 'porciones' || s === 'porción')      return 'porcion';
  return s;
}

const PESO    = new Set(['kg', 'lb', 'oz', 'g']);
const VOLUMEN = new Set(['lt', 'oz_fl', 'ml']);

// Factor para convertir entre unidades de rendimiento y unidad de uso en sub-recetas
function calcFactor(factorPadre, uso, usoUnidad, rendRaw, rendUnidad) {
  const rn = normUnit(rendUnidad);
  const un = normUnit(usoUnidad);
  if (!rn || rn === un) return factorPadre * uso / rendRaw;
  const key = `${rn}:${un}`;
  if (CONV[key] !== undefined) return factorPadre * uso / (rendRaw * CONV[key]);
  const rnF = PESO.has(rn) || VOLUMEN.has(rn);
  const unF = PESO.has(un) || VOLUMEN.has(un);
  if (rnF && unF && PESO.has(rn) !== PESO.has(un)) return factorPadre / rendRaw;
  return factorPadre * uso / rendRaw;
}

/**
 * Convierte una cantidad de la unidad de receta a la unidad base del producto.
 * Devuelve { cantidad, unidad } con la unidad real del resultado.
 */
function cantidadEnBase(cant, uReceta, uBase, uUnidadBaseProducto, factorConv, nombre) {
  const ur = normUnit(uReceta);
  const ub = normUnit(uBase);

  if (!ur || !ub || ur === ub) return { cantidad: cant, unidad: uBase || uReceta };

  // Prioridad 1: factor_conversion del producto (si la receta usa unidad_base del producto)
  if (factorConv && uUnidadBaseProducto && normUnit(uUnidadBaseProducto) === ur) {
    return { cantidad: cant / factorConv, unidad: uBase };
  }

  // Prioridad 2: conversión estándar
  const key = `${ur}:${ub}`;
  if (CONV[key] !== undefined) return { cantidad: cant * CONV[key], unidad: uBase };

  // Sin conversión conocida: mantener en unidad de receta y avisar
  console.warn(`  [!] Sin conversión "${uReceta}" → "${uBase}" para "${nombre}". Se reporta en "${uReceta}".`);
  return { cantidad: cant, unidad: uReceta };
}

// ── BFS para cargar árbol de recetas ─────────────────────────────────────────
async function loadAllRecetaTrees(pool, rootIds) {
  const allRecetas     = {};
  const allIngredients = {};
  const visited        = new Set();
  let toVisit          = [...rootIds.map(Number)];

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
      SELECT
        ri.receta_id,
        ri.cantidad_por_plato,
        ri.unidad                             AS unidad_receta,
        ri.producto_id,
        ri.sub_receta_id,
        COALESCE(p.nombre, sr.nombre)         AS ing_nombre,
        COALESCE(p.codigo, sr.codigo_origen)  AS ing_codigo,
        p.unidad                              AS prod_unidad,
        p.unidad_base                         AS prod_unidad_base,
        p.factor_conversion                   AS prod_factor_conv
      FROM receta_ingredientes ri
      LEFT JOIN productos p   ON p.id  = ri.producto_id
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

// Explosión recursiva de una receta hasta sus materias primas
function explode(recetaId, factorPadre, allIngredients, allRecetas, depth = 0) {
  if (depth > 8) return [];
  const ings = allIngredients[recetaId] || [];
  const result = [];

  for (const ing of ings) {
    const uso = parseFloat(ing.cantidad_por_plato) || 0;

    if (ing.sub_receta_id) {
      const sr = allRecetas[ing.sub_receta_id];
      if (!sr) {
        result.push({
          producto_id:       null,
          codigo:            ing.ing_codigo || '—',
          ingrediente:       ing.ing_nombre || '—',
          unidad_receta:     ing.unidad_receta || '',
          prod_unidad:       ing.unidad_receta || '',
          prod_unidad_base:  null,
          prod_factor_conv:  null,
          cant_por_plato:    uso * factorPadre,
        });
        continue;
      }
      const rend      = parseFloat(sr.rendimiento) || 1;
      const newFactor = calcFactor(factorPadre, uso, ing.unidad_receta, rend, sr.rendimiento_unidad);
      result.push(...explode(Number(sr.id), newFactor, allIngredients, allRecetas, depth + 1));
    } else {
      result.push({
        producto_id:      ing.producto_id,
        codigo:           ing.ing_codigo || '—',
        ingrediente:      ing.ing_nombre || '—',
        unidad_receta:    ing.unidad_receta || '',
        prod_unidad:      ing.prod_unidad  || ing.unidad_receta || '',
        prod_unidad_base: ing.prod_unidad_base || null,
        prod_factor_conv: ing.prod_factor_conv ? parseFloat(ing.prod_factor_conv) : null,
        cant_por_plato:   uso * factorPadre,
      });
    }
  }
  return result;
}

// ── Helpers de fecha ──────────────────────────────────────────────────────────
const MESES = ['enero','febrero','marzo','abril','mayo','junio',
               'julio','agosto','septiembre','octubre','noviembre','diciembre'];
const DIAS  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];

function fechaLarga(dateStr) {
  const d = new Date(dateStr + 'T12:00:00Z');
  return `${DIAS[d.getUTCDay()]}, ${d.getUTCDate()} de ${MESES[d.getUTCMonth()]} de ${d.getUTCFullYear()}`;
}

// ── Helpers de Excel ──────────────────────────────────────────────────────────
const BORDE = {
  top:    { style: 'thin', color: { argb: 'FFD0D0D0' } },
  bottom: { style: 'thin', color: { argb: 'FFD0D0D0' } },
  left:   { style: 'thin', color: { argb: 'FFD0D0D0' } },
  right:  { style: 'thin', color: { argb: 'FFD0D0D0' } },
};

function applyCell(cell, { bg, fg, bold = false, size = 10, hAlign = 'left', wrap = false, border = true } = {}) {
  if (bg)     cell.fill      = { type: 'pattern', pattern: 'solid', fgColor: { argb: bg } };
  if (fg)     cell.font      = { color: { argb: fg }, bold, size, name: 'Calibri' };
  cell.alignment = { vertical: 'middle', horizontal: hAlign, wrapText: wrap };
  if (border) cell.border    = BORDE;
}

function applyRow(row, cols, opts) {
  cols.forEach((opt, i) => {
    if (opt) applyCell(row.getCell(i + 1), opt);
  });
}

// ── Etiquetas de categorías (sin jerga técnica) ───────────────────────────────
const CAT_LABEL = {
  platos_fuertes:      'Plato Fuerte',
  entradas:            'Entrada',
  postres:             'Postre',
  desayunos:           'Desayuno',
  bebidas_sin_alcohol: 'Bebida sin alcohol',
  bebidas_alcohol:     'Bebida con alcohol',
  cerveza:             'Cerveza',
  otros:               'Otro',
};

// ── Colores ───────────────────────────────────────────────────────────────────
const C = {
  AZUL:      'FF1B3A5C',
  AZUL_SUB:  'FFD6E4F0',
  AZUL_DATO: 'FFEAF1F8',
  VERDE:     'FF1B5E20',
  VERDE_SUB: 'FFD4E6D0',
  VERDE_DATO:'FFF1F8F0',
  TOTAL_BG:  'FFE8F5E9',
  BLANCO:    'FFFFFFFF',
  GRIS:      'FFF5F5F5',
  NEGRO:     'FF1A1A1A',
  BLANC_TXT: 'FFFFFFFF',
};

// ── MAIN ──────────────────────────────────────────────────────────────────────
async function main() {
  const pool = new Pool(pgConfig);
  console.log(`Generando reporte — Casa Guirola — ${FECHA} ...`);

  // 1. Ventas del día ──────────────────────────────────────────────────────────
  const ventasRes = await pool.query(`
    SELECT
      vsd.producto_codigo,
      vsd.producto_nombre,
      vsd.categoria_key,
      SUM(vsd.cantidad_vendida) AS total_porciones,
      SUM(vsd.total)            AS total_venta
    FROM ventas_semanales vs
    JOIN ventas_semanales_detalle vsd ON vsd.venta_semanal_id = vs.id
    WHERE vs.sucursal_id   = $1
      AND vs.semana_inicio = $2
    GROUP BY vsd.producto_codigo, vsd.producto_nombre, vsd.categoria_key
    ORDER BY SUM(vsd.total) DESC
  `, [SUCURSAL_ID, FECHA]);

  const ventas = ventasRes.rows;
  console.log(`  Productos vendidos: ${ventas.length}`);
  if (!ventas.length) {
    console.log('Sin ventas para esa fecha y sucursal.');
    await pool.end(); return;
  }

  // 2. Cargar recetas ──────────────────────────────────────────────────────────
  const codigos = ventas.map(v => v.producto_codigo);
  const recetasRes = await pool.query(
    `SELECT id, nombre, codigo_origen, rendimiento, rendimiento_unidad
     FROM recetas WHERE codigo_origen = ANY($1) AND activa = true`,
    [codigos]
  );
  const recetaPorCodigo = {};
  for (const r of recetasRes.rows) recetaPorCodigo[r.codigo_origen] = r;
  console.log(`  Recetas encontradas: ${recetasRes.rowCount} de ${codigos.length} productos`);

  const rootIds = recetasRes.rows.map(r => Number(r.id));
  if (!rootIds.length) {
    console.log('Ningún producto tiene receta registrada. Saliendo.');
    await pool.end(); return;
  }

  // 3. BFS árbol de recetas ────────────────────────────────────────────────────
  console.log('  Cargando árbol de recetas...');
  const { allRecetas, allIngredients } = await loadAllRecetaTrees(pool, rootIds);
  for (const r of recetasRes.rows) allRecetas[r.id] = r;

  // 4. Explotar y acumular ─────────────────────────────────────────────────────
  // Clave: producto_id cuando está disponible; si no, codigo+unidad
  const acumulado = new Map();

  for (const v of ventas) {
    const receta = recetaPorCodigo[v.producto_codigo];
    if (!receta) continue;

    const porciones = parseFloat(v.total_porciones) || 0;
    if (porciones === 0) continue;

    const ings = explode(Number(receta.id), 1, allIngredients, allRecetas, 0);

    for (const ing of ings) {
      const cantReceta = ing.cant_por_plato * porciones;
      const { cantidad: cantBase, unidad: unidadFinal } = cantidadEnBase(
        cantReceta,
        ing.unidad_receta,
        ing.prod_unidad,
        ing.prod_unidad_base,
        ing.prod_factor_conv,
        ing.ingrediente
      );

      const clave = ing.producto_id
        ? `id:${ing.producto_id}:${normUnit(unidadFinal)}`
        : `cod:${ing.codigo}:${normUnit(unidadFinal)}`;

      if (!acumulado.has(clave)) {
        acumulado.set(clave, {
          ingrediente: ing.ingrediente,
          unidad:      unidadFinal,
          cantidad:    0,
        });
      }
      acumulado.get(clave).cantidad += cantBase;
    }
  }

  // Ordenar alfabéticamente por nombre de ingrediente
  const lista = [...acumulado.values()]
    .sort((a, b) => a.ingrediente.localeCompare(b.ingrediente, 'es', { sensitivity: 'base' }));

  console.log(`  Ingredientes únicos: ${lista.length}`);

  // 5. Generar Excel ────────────────────────────────────────────────────────────
  const wb = new ExcelJS.Workbook();
  wb.creator  = 'Cadejo Brewing Company';
  wb.created  = new Date();
  wb.modified = new Date();

  const totalVenta     = ventas.reduce((s, v) => s + (parseFloat(v.total_venta)     || 0), 0);
  const totalPorciones = ventas.reduce((s, v) => s + (parseFloat(v.total_porciones) || 0), 0);
  const fechaDisplay   = fechaLarga(FECHA);
  const generadoEn     = new Date().toLocaleString('es-SV', { dateStyle: 'short', timeStyle: 'short' });

  // ─────────────────────────────────────────────────────────────────────────────
  // HOJA 1: CONSUMO DE INGREDIENTES (hoja principal para gerencia)
  // ─────────────────────────────────────────────────────────────────────────────
  const ws1 = wb.addWorksheet('Consumo de Ingredientes', {
    pageSetup: { paperSize: 9, orientation: 'portrait', fitToPage: true, fitToHeight: 0, fitToWidth: 1 },
    properties: { tabColor: { argb: '1B3A5C' } },
  });
  ws1.views = [{ state: 'frozen', ySplit: 5 }];
  ws1.columns = [
    { key: 'num',   width: 6  },
    { key: 'ing',   width: 46 },
    { key: 'cant',  width: 18 },
    { key: 'unid',  width: 18 },
  ];

  // Fila 1 — Título
  const r1 = ws1.addRow(['CONSUMO DE INGREDIENTES — RESTAURANTE CASA GUIROLA (MANSIÓN)', '', '', '']);
  ws1.mergeCells(`A${r1.number}:D${r1.number}`);
  applyCell(r1.getCell(1), { bg: C.AZUL, fg: C.BLANC_TXT, bold: true, size: 13, hAlign: 'center' });
  r1.height = 32;

  // Fila 2 — Fecha
  const r2 = ws1.addRow([`Fecha: ${fechaDisplay}`, '', '', '']);
  ws1.mergeCells(`A${r2.number}:D${r2.number}`);
  applyCell(r2.getCell(1), { bg: C.AZUL_SUB, fg: C.NEGRO, bold: false, size: 11, hAlign: 'center' });
  r2.height = 22;

  // Fila 3 — Resumen de ventas del día
  const r3 = ws1.addRow([
    `Porciones vendidas: ${Math.round(totalPorciones).toLocaleString('es-SV')}   |   Venta del día: $${totalVenta.toLocaleString('es-SV', { minimumFractionDigits: 2 })}   |   Ingredientes distintos: ${lista.length}`,
    '', '', '',
  ]);
  ws1.mergeCells(`A${r3.number}:D${r3.number}`);
  applyCell(r3.getCell(1), { bg: C.AZUL_DATO, fg: C.NEGRO, size: 10, hAlign: 'center' });
  r3.height = 18;

  // Fila 4 — Separador
  const r4 = ws1.addRow(['', '', '', '']);
  r4.height = 6;

  // Fila 5 — Encabezados de columna
  const r5 = ws1.addRow(['N.', 'Ingrediente', 'Cantidad', 'Unidad de Medida']);
  applyCell(r5.getCell(1), { bg: C.AZUL, fg: C.BLANC_TXT, bold: true, size: 11, hAlign: 'center' });
  applyCell(r5.getCell(2), { bg: C.AZUL, fg: C.BLANC_TXT, bold: true, size: 11, hAlign: 'left' });
  applyCell(r5.getCell(3), { bg: C.AZUL, fg: C.BLANC_TXT, bold: true, size: 11, hAlign: 'right' });
  applyCell(r5.getCell(4), { bg: C.AZUL, fg: C.BLANC_TXT, bold: true, size: 11, hAlign: 'center' });
  r5.height = 24;

  // Filas de datos
  for (let i = 0; i < lista.length; i++) {
    const item = lista[i];
    const bg   = i % 2 === 0 ? C.BLANCO : C.GRIS;
    const row  = ws1.addRow([i + 1, item.ingrediente, null, item.unidad.toUpperCase()]);

    // Redondear la cantidad: 3 decimales si < 100, 2 si >= 100
    const cant = item.cantidad;
    row.getCell(3).value  = Math.round(cant * 1000) / 1000;
    row.getCell(3).numFmt = cant >= 100 ? '#,##0.00' : '#,##0.000';

    applyCell(row.getCell(1), { bg, fg: C.NEGRO, size: 10, hAlign: 'center' });
    applyCell(row.getCell(2), { bg, fg: C.NEGRO, size: 10, hAlign: 'left' });
    applyCell(row.getCell(3), { bg, fg: C.NEGRO, size: 10, hAlign: 'right' });
    applyCell(row.getCell(4), { bg, fg: C.NEGRO, size: 10, hAlign: 'center' });
    row.height = 18;
  }

  // Separador
  ws1.addRow(['', '', '', '']).height = 4;

  // Fila total (solo cuenta de ingredientes — las unidades son mixtas)
  const rTot = ws1.addRow(['', `Total de ingredientes listados: ${lista.length}`, '', '']);
  ws1.mergeCells(`B${rTot.number}:D${rTot.number}`);
  applyCell(rTot.getCell(1), { bg: C.TOTAL_BG, fg: C.NEGRO, bold: true, size: 11, hAlign: 'center' });
  applyCell(rTot.getCell(2), { bg: C.TOTAL_BG, fg: C.NEGRO, bold: true, size: 11, hAlign: 'left' });
  rTot.height = 22;

  // Nota al pie
  ws1.addRow(['', '', '', '']).height = 8;
  const rNota = ws1.addRow(['', 'Las cantidades representan el consumo teórico calculado con base en las recetas registradas en el sistema.', '', '']);
  ws1.mergeCells(`B${rNota.number}:D${rNota.number}`);
  rNota.getCell(2).font      = { italic: true, size: 9, color: { argb: 'FF777777' }, name: 'Calibri' };
  rNota.getCell(2).alignment = { vertical: 'middle', wrapText: true };
  rNota.height = 28;

  const rGen = ws1.addRow(['', `Generado el ${generadoEn}`, '', '']);
  ws1.mergeCells(`B${rGen.number}:D${rGen.number}`);
  rGen.getCell(2).font      = { italic: true, size: 9, color: { argb: 'FFAAAAAA' }, name: 'Calibri' };
  rGen.getCell(2).alignment = { vertical: 'middle' };
  rGen.height = 16;

  // ─────────────────────────────────────────────────────────────────────────────
  // HOJA 2: VENTAS DEL DÍA (detalle de lo vendido)
  // ─────────────────────────────────────────────────────────────────────────────
  const ws2 = wb.addWorksheet('Ventas del Día', {
    properties: { tabColor: { argb: '1B5E20' } },
  });
  ws2.views = [{ state: 'frozen', ySplit: 5 }];
  ws2.columns = [
    { key: 'num',   width: 6  },
    { key: 'prod',  width: 46 },
    { key: 'cat',   width: 18 },
    { key: 'porc',  width: 14 },
    { key: 'venta', width: 14 },
  ];

  // Título
  const v1 = ws2.addRow(['VENTAS DEL DÍA — RESTAURANTE CASA GUIROLA (MANSIÓN)', '', '', '', '']);
  ws2.mergeCells(`A${v1.number}:E${v1.number}`);
  applyCell(v1.getCell(1), { bg: C.VERDE, fg: C.BLANC_TXT, bold: true, size: 13, hAlign: 'center' });
  v1.height = 32;

  // Fecha
  const v2 = ws2.addRow([`Fecha: ${fechaDisplay}`, '', '', '', '']);
  ws2.mergeCells(`A${v2.number}:E${v2.number}`);
  applyCell(v2.getCell(1), { bg: C.VERDE_SUB, fg: C.NEGRO, size: 11, hAlign: 'center' });
  v2.height = 22;

  // Resumen
  const v3 = ws2.addRow([
    `Venta total: $${totalVenta.toLocaleString('es-SV', { minimumFractionDigits: 2 })}   |   Porciones: ${Math.round(totalPorciones).toLocaleString('es-SV')}   |   Productos distintos: ${ventas.length}`,
    '', '', '', '',
  ]);
  ws2.mergeCells(`A${v3.number}:E${v3.number}`);
  applyCell(v3.getCell(1), { bg: C.VERDE_DATO, fg: C.NEGRO, size: 10, hAlign: 'center' });
  v3.height = 18;

  // Separador
  ws2.addRow(['', '', '', '', '']).height = 6;

  // Encabezados
  const v5 = ws2.addRow(['N.', 'Producto', 'Categoría', 'Porciones', 'Venta']);
  applyCell(v5.getCell(1), { bg: C.VERDE, fg: C.BLANC_TXT, bold: true, size: 11, hAlign: 'center' });
  applyCell(v5.getCell(2), { bg: C.VERDE, fg: C.BLANC_TXT, bold: true, size: 11, hAlign: 'left' });
  applyCell(v5.getCell(3), { bg: C.VERDE, fg: C.BLANC_TXT, bold: true, size: 11, hAlign: 'center' });
  applyCell(v5.getCell(4), { bg: C.VERDE, fg: C.BLANC_TXT, bold: true, size: 11, hAlign: 'right' });
  applyCell(v5.getCell(5), { bg: C.VERDE, fg: C.BLANC_TXT, bold: true, size: 11, hAlign: 'right' });
  v5.height = 24;

  let sumPorc = 0, sumVenta = 0;
  for (let i = 0; i < ventas.length; i++) {
    const v   = ventas[i];
    const bg  = i % 2 === 0 ? C.BLANCO : C.VERDE_DATO;
    const por = parseFloat(v.total_porciones) || 0;
    const ven = parseFloat(v.total_venta)     || 0;
    sumPorc  += por;
    sumVenta += ven;

    const vr = ws2.addRow([
      i + 1,
      v.producto_nombre,
      CAT_LABEL[v.categoria_key] || v.categoria_key,
      por,
      ven,
    ]);
    applyCell(vr.getCell(1), { bg, fg: C.NEGRO, size: 10, hAlign: 'center' });
    applyCell(vr.getCell(2), { bg, fg: C.NEGRO, size: 10, hAlign: 'left' });
    applyCell(vr.getCell(3), { bg, fg: '555555', size: 9,  hAlign: 'center' });
    applyCell(vr.getCell(4), { bg, fg: C.NEGRO, size: 10, hAlign: 'right' });
    applyCell(vr.getCell(5), { bg, fg: C.NEGRO, size: 10, hAlign: 'right' });
    vr.getCell(4).numFmt = '#,##0.00';
    vr.getCell(5).numFmt = '"$"#,##0.00';
    vr.height = 18;
  }

  // Totales
  ws2.addRow(['', '', '', '', '']).height = 4;
  const vTot = ws2.addRow(['', 'TOTALES', '', sumPorc, sumVenta]);
  applyCell(vTot.getCell(1), { bg: C.TOTAL_BG, fg: C.NEGRO, bold: true, size: 11, hAlign: 'center' });
  applyCell(vTot.getCell(2), { bg: C.TOTAL_BG, fg: C.NEGRO, bold: true, size: 11, hAlign: 'left' });
  applyCell(vTot.getCell(3), { bg: C.TOTAL_BG, fg: C.NEGRO, bold: true, size: 11, hAlign: 'center' });
  applyCell(vTot.getCell(4), { bg: C.TOTAL_BG, fg: C.NEGRO, bold: true, size: 11, hAlign: 'right' });
  applyCell(vTot.getCell(5), { bg: C.TOTAL_BG, fg: C.NEGRO, bold: true, size: 11, hAlign: 'right' });
  vTot.getCell(4).numFmt = '#,##0.00';
  vTot.getCell(5).numFmt = '"$"#,##0.00';
  vTot.height = 22;

  // ─────────────────────────────────────────────────────────────────────────────
  // Guardar archivo
  // ─────────────────────────────────────────────────────────────────────────────
  const fileName = `Consumo_Ingredientes_CasaGuirola_${FECHA}.xlsx`;
  const filePath = path.join(process.env.USERPROFILE || __dirname, 'Downloads', fileName);
  await wb.xlsx.writeFile(filePath);

  console.log(`\nArchivo generado: ${filePath}`);
  console.log(`  Ingredientes: ${lista.length} | Productos vendidos: ${ventas.length} | Venta: $${totalVenta.toFixed(2)}`);
  await pool.end();
}

main().catch(e => {
  console.error('ERROR:', e.message);
  process.exit(1);
});
