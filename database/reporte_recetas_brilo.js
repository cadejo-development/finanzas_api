/**
 * reporte_recetas_brilo.js
 *
 * Reporte Excel completo de recetas en Brilo (SQL Server):
 *   - Activas en menú
 *   - Activas sin menú
 *   - Inactivas en menú
 *   - Inactivas sin menú
 *   + Sucursales donde aparece cada receta en teclas
 */
const sql     = require('mssql');
const ExcelJS = require('exceljs');
const path    = require('path');

const SQL_CFG = {
  user: 'olimporeader', password: 'olimporeader',
  server: '10.0.4.20', port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 20000 },
};

// Sucursales que ya no existen físicamente — excluir del reporte
const SUC_EXCLUIR = new Set(['RES - SANTA ROSA', 'RES - SAN MIGUEL']);

// Categorías válidas de receta (las mismas que usa el sync)
const CAT_VALIDAS = new Set([
  'Bebidas con Alcohol', 'Bebidas Malcriadas AE2 s/a', 'Bebidas sin Alcohol',
  'Platos Desayunos', 'Platos Empleados', 'Platos Entradas', 'Platos Eventos',
  'Platos Extras Clientes', 'Platos Fuertes', 'Platos Infantiles',
  'Platos Malcriadas AE2', 'Platos Mascotas', 'Platos Postres',
  'Sub-Recetas', 'Sub-Receta', 'Sub Recetas',
]);

function hdr(ws, color = 'FF1F3864') {
  const r = ws.getRow(1);
  r.font      = { bold: true, color: { argb: 'FFFFFFFF' } };
  r.fill      = { type: 'pattern', pattern: 'solid', fgColor: { argb: color } };
  r.alignment = { horizontal: 'center', wrapText: true };
  r.height    = 22;
}
function widths(ws, arr) {
  arr.forEach((w, i) => { ws.getColumn(i + 1).width = w; });
  ws.getColumn(4).numFmt = '"$"#,##0.00'; // columna Precio
}

async function main() {
  console.log('Conectando a SQL Server...');
  const pool = await sql.connect(SQL_CFG);
  console.log('OK\n');

  // 1. Sucursales de Brilo
  console.log('Leyendo sucursales...');
  const sucRes = await pool.request().query(`
    SELECT sucId, LTRIM(RTRIM(sucNombre)) AS nombre
    FROM olComun.dbo.Sucursales WITH(NOLOCK)
    ORDER BY sucNombre
  `);
  const sucMap = {};
  sucRes.recordset.forEach(r => { sucMap[r.sucId] = r.nombre; });

  // 2. Menú por producto
  console.log('Leyendo menú (ProductoXCocinaXSucRst)...');
  const menuRes = await pool.request().query(`
    SELECT DISTINCT p.proCodigo, px.sucId,
                    LTRIM(RTRIM(s.sucNombre)) AS sucNombre
    FROM olRestaurante.dbo.ProductoXCocinaXSucRst px WITH(NOLOCK)
    JOIN olComun.dbo.Productos p WITH(NOLOCK) ON p.proId = px.proId
    JOIN olComun.dbo.Sucursales s WITH(NOLOCK) ON s.sucId = px.sucId
    WHERE p.proCodigo IS NOT NULL
  `);
  const menuMap = {}; // codigo → Set de nombres de sucursal (excluyendo las inactivas)
  menuRes.recordset.forEach(r => {
    if (SUC_EXCLUIR.has(r.sucNombre)) return; // ignorar sucursales que ya no existen
    const cod = (r.proCodigo || '').trim();
    if (!menuMap[cod]) menuMap[cod] = new Set();
    menuMap[cod].add(r.sucNombre);
  });

  // 3. Todas las recetas de Brilo (filtradas por categoría válida)
  console.log('Leyendo recetas...');
  const recRes = await pool.request().query(`
    SELECT p.proId,
           LTRIM(RTRIM(p.proCodigo)) AS codigo,
           LTRIM(RTRIM(p.proNombre)) AS nombre,
           p.proActivo               AS activo,
           cpr.cprNombre             AS categoria,
           cpr.cprCodigo             AS cat_codigo,
           ISNULL(p.proPrecio, 0)    AS precio,
           (SELECT COUNT(1) FROM olComun.dbo.MaterialesXProducto mx WITH(NOLOCK)
            WHERE mx.proId = p.proId AND mx.mxprActivo = 1 AND mx.mxprEliminado = 0) AS num_ing
    FROM olComun.dbo.Productos p WITH(NOLOCK)
    INNER JOIN olComun.dbo.CategoriasProductos cpr WITH(NOLOCK) ON cpr.cprId = p.cprId
    WHERE p.proCodigo IS NOT NULL AND LTRIM(RTRIM(p.proCodigo)) != ''
      AND (p.proCodigo LIKE 'PL%'   OR p.proCodigo LIKE 'SUBR%'
        OR p.proCodigo LIKE 'PR%'   OR p.proCodigo LIKE 'PRD%'
        OR p.proCodigo LIKE 'LLPL%' OR p.proCodigo LIKE 'HZPL%'
        OR p.proCodigo LIKE 'HZPR%' OR p.proCodigo LIKE 'EVPL%'
        OR p.proCodigo LIKE 'VOPL%' OR p.proCodigo LIKE 'PMPL%')
    ORDER BY p.proCodigo
  `);

  // Filtrar por categorías válidas
  const recetas = recRes.recordset.filter(r => CAT_VALIDAS.has(r.categoria));
  console.log(`Total recetas válidas: ${recetas.length}`);

  // 4. Categorizar
  const activasMenu    = [];
  const activasSinMenu = [];
  const inactivasMenu  = [];
  const inactivasSinMenu = [];

  recetas.forEach(r => {
    const sucursales = menuMap[r.codigo] ? [...menuMap[r.codigo]].sort().join(', ') : '';
    const enMenu     = !!menuMap[r.codigo];
    const row        = {
      codigo:     r.codigo,
      nombre:     r.nombre,
      categoria:  r.categoria,
      precio:     parseFloat((parseFloat(r.precio) || 0).toFixed(2)),
      num_ing:    r.num_ing,
      en_menu:    enMenu ? 'Sí' : 'No',
      sucursales: sucursales,
      num_suc:    menuMap[r.codigo] ? menuMap[r.codigo].size : 0,
    };

    if (r.activo && enMenu)       activasMenu.push(row);
    else if (r.activo && !enMenu) activasSinMenu.push(row);
    else if (!r.activo && enMenu) inactivasMenu.push(row);
    else                          inactivasSinMenu.push(row);
  });

  console.log(`  Activas + en menú:       ${activasMenu.length}`);
  console.log(`  Activas + sin menú:      ${activasSinMenu.length}`);
  console.log(`  Inactivas + en menú:     ${inactivasMenu.length}`);
  console.log(`  Inactivas + sin menú:    ${inactivasSinMenu.length}`);

  // 5. Generar Excel
  console.log('\nGenerando Excel...');
  const wb = new ExcelJS.Workbook();

  const COLS = ['Código', 'Nombre', 'Categoría', 'Precio', '# Ingredientes', 'En Menú', '# Sucursales', 'Sucursales'];
  const WIDTHS = [18, 50, 24, 10, 14, 10, 12, 70];

  function addRow(ws, r) {
    return ws.addRow([r.codigo, r.nombre, r.categoria, r.precio, r.num_ing, r.en_menu, r.num_suc, r.sucursales]);
  }

  // ── Resumen ──
  const ws0 = wb.addWorksheet('Resumen');
  ws0.addRow(['Reporte de Recetas Brilo']).font = { bold: true, size: 13 };
  ws0.addRow(['Generado:', new Date().toLocaleString('es-GT')]);
  ws0.addRow([]);
  [
    ['Total recetas válidas en Brilo', recetas.length, ''],
    [''],
    ['ACTIVAS + en menú (teclas)',      activasMenu.length,      'Están en producción activa'],
    ['ACTIVAS + sin menú',              activasSinMenu.length,   'Activas pero sin asignar a teclas'],
    ['INACTIVAS + en menú',             inactivasMenu.length,    'En teclas pero inactiva en catálogo ⚠'],
    ['INACTIVAS + sin menú',            inactivasSinMenu.length, 'No activas, no en menú'],
  ].forEach(([label, val, nota]) => {
    const r = ws0.addRow([label, val ?? '', nota ?? '']);
    if (String(label).startsWith('ACTIVAS') || String(label).startsWith('INACTIVAS')) {
      r.getCell(1).font = { bold: true };
    }
    if (String(label).startsWith('INACTIVAS + en menú')) {
      r.getCell(3).font = { bold: true, color: { argb: 'FFCC0000' } };
    }
  });
  ws0.getColumn(1).width = 38; ws0.getColumn(2).width = 12; ws0.getColumn(3).width = 38;

  // ── Activas en menú ──
  const ws1 = wb.addWorksheet('Activas en Menú');
  ws1.addRow(COLS); hdr(ws1, 'FF1E4D2B');
  activasMenu.sort((a,b) => a.codigo.localeCompare(b.codigo)).forEach(r => addRow(ws1, r));
  widths(ws1, WIDTHS);

  // ── Activas sin menú ──
  const ws2 = wb.addWorksheet('Activas sin Menú');
  ws2.addRow(COLS); hdr(ws2, 'FF1F3864');
  activasSinMenu.sort((a,b) => a.codigo.localeCompare(b.codigo)).forEach(r => addRow(ws2, r));
  widths(ws2, WIDTHS);

  // ── Inactivas en menú ──
  const ws3 = wb.addWorksheet('⚠ Inactivas en Menú');
  ws3.addRow(COLS); hdr(ws3, 'FF7B0000');
  inactivasMenu.sort((a,b) => a.codigo.localeCompare(b.codigo)).forEach(r => {
    const row = addRow(ws3, r);
    row.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFFFF2CC' } };
  });
  widths(ws3, WIDTHS);

  // ── Inactivas sin menú ──
  const ws4 = wb.addWorksheet('Inactivas sin Menú');
  ws4.addRow(COLS); hdr(ws4, 'FF555555');
  inactivasSinMenu.sort((a,b) => a.codigo.localeCompare(b.codigo)).forEach(r => addRow(ws4, r));
  widths(ws4, WIDTHS);

  const out = path.join(__dirname, `reporte_recetas_brilo_${new Date().toISOString().slice(0,10)}.xlsx`);
  await wb.xlsx.writeFile(out);
  console.log(`\n✓ Excel generado: ${out}`);

  pool.close();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
