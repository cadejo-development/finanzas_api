/**
 * export_empleados_activos.js
 *
 * Genera un Excel con todos los empleados activos.
 * Columnas: Código | Nombres | Apellidos | Puesto | Departamento/Sucursal | Correo
 *
 * Casa Matriz (SUC-CM): muestra el departamento (o "Casa Matriz" si no tiene).
 * Restaurantes:         muestra el nombre de la sucursal.
 *
 * Uso:
 *   node export_empleados_activos.js
 */

const { Pool }   = require('pg');
const ExcelJS    = require('exceljs');
const path       = require('path');

const PG_CFG = {
  host:     'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port:     5432,
  database: 'core_db',
  user:     'cadejo_admin',
  password: 'Holamundo#3..',
  ssl:      { rejectUnauthorized: false },
  connectionTimeoutMillis: 30000,
};

const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s  => console.log(`[${ts()}] ${s}`);

async function run() {
  log('Conectando a PostgreSQL RDS...');
  const pg = new Pool(PG_CFG);
  await pg.query('SELECT 1');
  log('Conectado.\n');

  const { rows } = await pg.query(`
    SELECT
      e.codigo                                              AS codigo,
      e.nombres                                             AS nombres,
      e.apellidos                                           AS apellidos,
      COALESCE(c.nombre, '—')                               AS puesto,
      CASE
        WHEN s.codigo = 'SUC-CM' THEN COALESCE(d.nombre, 'Casa Matriz / Corporativo')
        ELSE COALESCE(d.nombre, s.nombre, '—')
      END                                                   AS area,
      COALESCE(e.email, '')                                 AS correo
    FROM empleados e
    LEFT JOIN cargos       c ON c.id = e.cargo_id
    LEFT JOIN sucursales   s ON s.id = e.sucursal_id
    LEFT JOIN departamentos d ON d.id = e.departamento_id
    WHERE e.activo = true
    ORDER BY
      CASE WHEN s.codigo = 'SUC-CM' THEN 0 ELSE 1 END,
      s.nombre,
      COALESCE(d.nombre, ''),
      e.apellidos,
      e.nombres
  `);

  log(`Empleados activos encontrados: ${rows.length}`);
  await pg.end();

  // ── Construir Excel ─────────────────────────────────────────────────────────
  const wb   = new ExcelJS.Workbook();
  wb.creator = 'Cadejo RRHH';
  wb.created = new Date();

  const ws = wb.addWorksheet('Empleados Activos', {
    views: [{ state: 'frozen', ySplit: 1 }],
  });

  // Columnas
  ws.columns = [
    { header: 'Código',                  key: 'codigo',    width: 14 },
    { header: 'Nombres',                 key: 'nombres',   width: 28 },
    { header: 'Apellidos',               key: 'apellidos', width: 28 },
    { header: 'Puesto',                  key: 'puesto',    width: 36 },
    { header: 'Departamento / Sucursal', key: 'area',      width: 34 },
    { header: 'Correo',                  key: 'correo',    width: 38 },
  ];

  // Estilo encabezado
  const headerRow = ws.getRow(1);
  headerRow.height = 28;
  headerRow.eachCell(cell => {
    cell.fill   = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1C1C1C' } };
    cell.font   = { bold: true, color: { argb: 'FFFAA026' }, size: 11, name: 'Calibri' };
    cell.alignment = { vertical: 'middle', horizontal: 'center', wrapText: false };
    cell.border = {
      bottom: { style: 'medium', color: { argb: 'FFFAA026' } },
    };
  });

  // Colores alternos por área para legibilidad
  const areaColor = {};
  const PALETTE = [
    'FFF5F5F5', 'FFEEF6FF', 'FFFFE8CC', 'FFF0FFF4',
    'FFFFF0F0', 'FFF5EEFF', 'FFECFEFF', 'FFFFF9E6',
  ];
  let paletteIdx = 0;

  rows.forEach((r, i) => {
    if (!(r.area in areaColor)) {
      areaColor[r.area] = PALETTE[paletteIdx % PALETTE.length];
      paletteIdx++;
    }
    const bg = areaColor[r.area];

    const row = ws.addRow({
      codigo:    r.codigo,
      nombres:   r.nombres,
      apellidos: r.apellidos,
      puesto:    r.puesto,
      area:      r.area,
      correo:    r.correo,
    });

    row.height = 20;
    row.eachCell(cell => {
      cell.fill      = { type: 'pattern', pattern: 'solid', fgColor: { argb: bg } };
      cell.font      = { size: 10, name: 'Calibri' };
      cell.alignment = { vertical: 'middle' };
      cell.border    = {
        bottom: { style: 'hair', color: { argb: 'FFDDDDDD' } },
      };
    });

    // Correo en azul clicable
    if (r.correo) {
      const cCell = row.getCell('correo');
      cCell.value = { text: r.correo, hyperlink: `mailto:${r.correo}` };
      cCell.font  = { size: 10, name: 'Calibri', color: { argb: 'FF0563C1' }, underline: true };
    }
  });

  // Autofilter en encabezado
  ws.autoFilter = { from: 'A1', to: 'F1' };

  // Guardar
  const fecha    = new Date().toISOString().slice(0, 10);
  const fileName = `empleados_activos_${fecha}.xlsx`;
  const outPath  = path.join(__dirname, '..', fileName);
  await wb.xlsx.writeFile(outPath);

  log(`\nArchivo generado: ${outPath}`);
  log(`Total empleados: ${rows.length}`);
}

run().catch(e => { console.error(e); process.exit(1); });
