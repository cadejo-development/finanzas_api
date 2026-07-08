/**
 * reporte_empleados_activos.js
 *
 * Genera un reporte Excel de empleados activos ordenado por
 * departamento / sucursal y apellidos.
 *
 * Columnas: Código | Apellidos | Nombres | Departamento/Área | Cargo | Fecha ingreso | Correo
 * Estilo: profesional, sin colores llamativos.
 *
 * Uso: node reporte_empleados_activos.js
 */

const { Pool }  = require('pg');
const ExcelJS   = require('exceljs');
const path      = require('path');

const PG_CFG = {
  host:     'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port:     5432, database: 'core_db',
  user:     'cadejo_admin', password: 'Holamundo#3..',
  ssl:      { rejectUnauthorized: false },
};

async function main() {
  const pg = new Pool(PG_CFG);
  console.log('Conectando...');
  await pg.query('SELECT 1');
  console.log('OK\n');

  const { rows } = await pg.query(`
    SELECT
      e.codigo                                                      AS codigo,
      e.apellidos                                                   AS apellidos,
      e.nombres                                                     AS nombres,
      CASE
        WHEN s.codigo = 'SUC-CM' THEN COALESCE(
          (SELECT nombre FROM departamentos
           WHERE jefe_empleado_id = e.id AND activo = true
           ORDER BY nombre LIMIT 1),
          d.nombre,
          'Casa Matriz / Corporativo'
        )
        ELSE COALESCE(s.nombre, '—')
      END                                                           AS area,
      COALESCE(c.nombre, '—')                                       AS cargo,
      TO_CHAR(e.fecha_ingreso, 'DD/MM/YYYY')                       AS fecha_ingreso,
      COALESCE(
        NULLIF(u.email, ''),
        e.email,
        ''
      )                                                             AS correo
    FROM empleados e
    LEFT JOIN cargos       c ON c.id  = e.cargo_id
    LEFT JOIN sucursales   s ON s.id  = e.sucursal_id
    LEFT JOIN departamentos d ON d.id  = e.departamento_id AND d.activo = true
    LEFT JOIN users         u ON u.id  = e.user_id
    WHERE e.activo = true
    ORDER BY
      CASE WHEN s.codigo = 'SUC-CM' THEN 0 ELSE 1 END,
      CASE
        WHEN s.codigo = 'SUC-CM' THEN COALESCE(
          (SELECT nombre FROM departamentos
           WHERE jefe_empleado_id = e.id AND activo = true
           ORDER BY nombre LIMIT 1),
          d.nombre,
          'Casa Matriz / Corporativo'
        )
        ELSE COALESCE(s.nombre, '—')
      END,
      e.apellidos,
      e.nombres
  `);

  await pg.end();
  console.log(`Empleados activos: ${rows.length}`);

  // ── Excel ────────────────────────────────────────────────────────
  const wb = new ExcelJS.Workbook();
  wb.creator = 'Cadejo Brewing Company';
  wb.created = new Date();

  const ws = wb.addWorksheet('Empleados Activos', {
    views: [{ state: 'frozen', ySplit: 2 }],
    pageSetup: {
      paperSize: 9,       // A4
      orientation: 'landscape',
      fitToPage: true,
      fitToWidth: 1,
      fitToHeight: 0,
      margins: { left: 0.5, right: 0.5, top: 0.75, bottom: 0.75, header: 0.3, footer: 0.3 },
    },
  });

  // ── Fila de título ───────────────────────────────────────────────
  ws.mergeCells('A1:G1');
  const titleCell = ws.getCell('A1');
  const fecha = new Date().toLocaleDateString('es-SV', { day: '2-digit', month: 'long', year: 'numeric' });
  titleCell.value    = `Listado de Empleados Activos — ${fecha}`;
  titleCell.font     = { bold: true, size: 13, name: 'Calibri', color: { argb: 'FF1A1A1A' } };
  titleCell.alignment = { horizontal: 'center', vertical: 'middle' };
  ws.getRow(1).height = 26;

  // ── Columnas y encabezado ────────────────────────────────────────
  ws.columns = [
    { key: 'codigo',        width: 13 },
    { key: 'apellidos',     width: 28 },
    { key: 'nombres',       width: 28 },
    { key: 'area',          width: 32 },
    { key: 'cargo',         width: 32 },
    { key: 'fecha_ingreso', width: 16 },
    { key: 'correo',        width: 36 },
  ];

  const HEADERS = ['Código', 'Apellidos', 'Nombres', 'Departamento / Sucursal', 'Cargo', 'Fecha de ingreso', 'Correo'];
  const headerRow = ws.getRow(2);
  HEADERS.forEach((h, i) => {
    const cell = headerRow.getCell(i + 1);
    cell.value = h;
    cell.font  = { bold: true, size: 10, name: 'Calibri', color: { argb: 'FFFFFFFF' } };
    cell.fill  = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF2C2C2C' } };
    cell.alignment = { vertical: 'middle', horizontal: 'center', wrapText: false };
    cell.border = {
      top:    { style: 'thin', color: { argb: 'FF444444' } },
      bottom: { style: 'thin', color: { argb: 'FF444444' } },
      left:   { style: 'thin', color: { argb: 'FF444444' } },
      right:  { style: 'thin', color: { argb: 'FF444444' } },
    };
  });
  headerRow.height = 22;

  // ── Datos agrupados por área ─────────────────────────────────────
  let areaActual = null;
  let totalPorArea = 0;

  const BORDER_DATA = {
    top:    { style: 'hair', color: { argb: 'FFCCCCCC' } },
    bottom: { style: 'hair', color: { argb: 'FFCCCCCC' } },
    left:   { style: 'thin', color: { argb: 'FFDDDDDD' } },
    right:  { style: 'thin', color: { argb: 'FFDDDDDD' } },
  };

  for (let i = 0; i < rows.length; i++) {
    const r = rows[i];

    // Fila separadora de grupo cuando cambia el área
    if (r.area !== areaActual) {
      areaActual  = r.area;
      totalPorArea = rows.filter(x => x.area === r.area).length;

      ws.mergeCells(`A${ws.rowCount + 1}:G${ws.rowCount + 1}`);
      const sepRow  = ws.lastRow;
      const sepCell = sepRow.getCell(1);
      sepCell.value = `${r.area}   (${totalPorArea} empleado${totalPorArea !== 1 ? 's' : ''})`;
      sepCell.font  = { bold: true, size: 10, name: 'Calibri', color: { argb: 'FF1A1A1A' } };
      sepCell.fill  = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFEEEEEE' } };
      sepCell.alignment = { vertical: 'middle', indent: 1 };
      sepCell.border = {
        top:    { style: 'medium', color: { argb: 'FF999999' } },
        bottom: { style: 'thin',   color: { argb: 'FFBBBBBB' } },
      };
      sepRow.height = 18;
    }

    // Fila de empleado (blancas y grises alternos)
    const dataRow = ws.addRow({
      codigo:        r.codigo,
      apellidos:     r.apellidos,
      nombres:       r.nombres,
      area:          r.area,
      cargo:         r.cargo,
      fecha_ingreso: r.fecha_ingreso ?? '—',
      correo:        r.correo,
    });

    const bgColor = i % 2 === 0 ? 'FFFFFFFF' : 'FFF8F8F8';
    dataRow.height = 18;
    dataRow.eachCell({ includeEmpty: true }, (cell, col) => {
      cell.fill      = { type: 'pattern', pattern: 'solid', fgColor: { argb: bgColor } };
      cell.font      = { size: 10, name: 'Calibri', color: { argb: 'FF1A1A1A' } };
      cell.alignment = { vertical: 'middle', horizontal: col === 6 ? 'center' : 'left' };
      cell.border    = BORDER_DATA;
    });

    // Correo clicable
    if (r.correo) {
      const cCell = dataRow.getCell('correo');
      cCell.value = { text: r.correo, hyperlink: `mailto:${r.correo}` };
      cCell.font  = { size: 10, name: 'Calibri', color: { argb: 'FF0563C1' }, underline: true };
    }
  }

  // ── Fila de total ────────────────────────────────────────────────
  ws.addRow([]);
  ws.mergeCells(`A${ws.rowCount + 1}:G${ws.rowCount + 1}`);
  const totRow  = ws.lastRow;
  const totCell = totRow.getCell(1);
  totCell.value = `Total de empleados activos: ${rows.length}`;
  totCell.font  = { bold: true, size: 10, name: 'Calibri', color: { argb: 'FF1A1A1A' } };
  totCell.alignment = { horizontal: 'right', vertical: 'middle', indent: 1 };
  totCell.border = { top: { style: 'medium', color: { argb: 'FF999999' } } };
  totRow.height = 20;

  // Autofilter solo en encabezado
  ws.autoFilter = { from: 'A2', to: 'G2' };

  // ── Guardar ──────────────────────────────────────────────────────
  const hoy      = new Date().toISOString().slice(0, 10);
  const fileName = `reporte_empleados_activos_${hoy}.xlsx`;
  const outPath  = path.join(__dirname, fileName);
  await wb.xlsx.writeFile(outPath);

  console.log(`\n✓ Excel generado: ${outPath}`);
  console.log(`  Total empleados: ${rows.length}`);
}

main().catch(e => { console.error('Error:', e.message); process.exit(1); });
