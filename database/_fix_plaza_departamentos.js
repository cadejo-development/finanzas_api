/**
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

 * _fix_plaza_departamentos.js
 *
 * Asigna departamento_id correcto a plazas que tienen sucursal_unidad
 * pero departamento_id = null.
 *
 * Uso:
 *   node _fix_plaza_departamentos.js           ← dry-run
 *   node _fix_plaza_departamentos.js --apply   ← aplica
 */

const { Pool } = require('pg');
const APPLY = process.argv.includes('--apply');
const pg = new Pool({
  host: process.env.DB_HOST,
  port: 5432, database: 'core_db',
  user: process.env.DB_USERNAME, password: process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
});

async function main() {
  console.log(`Modo: ${APPLY ? '✅ APPLY' : '🔍 DRY-RUN'}\n`);

  // 1. Todos los departamentos
  const { rows: deptos } = await pg.query(`SELECT id, nombre FROM departamentos WHERE activo = true ORDER BY nombre`);
  console.log('Departamentos en DB:');
  deptos.forEach(d => console.log(`  ${d.id.toString().padStart(3)} | ${d.nombre}`));

  // 2. Plazas sin departamento_id (con sucursal_unidad conocida)
  const { rows: plazasSin } = await pg.query(`
    SELECT id, codigo, puesto, sucursal_unidad, estado
    FROM plazas
    WHERE departamento_id IS NULL AND sucursal_unidad IS NOT NULL
    ORDER BY sucursal_unidad, codigo
  `);
  console.log(`\nPlazas sin departamento_id: ${plazasSin.length}`);

  // 3. Mapa manual sucursal_unidad (Excel) → nombre departamento en DB
  const MAPA = {
    'AE Malcriadas':                 'RESTAURANTE MALCRIADAS AE',
    'Aeropuerto 1':                  'RESTAURANTE AEROPUERTO 1',
    'Aeropuerto 2':                  'RESTAURANTE AEROPUERTO 2',
    'Alimentos y Bebidas':           'ALIMENTOS Y BEBIDAS',
    'Bodega':                        'BODEGA',
    'Casa Matriz / Corporativo':     null,   // no tiene dept específico → dejar null
    'Centro de Producción - Restaurantes': 'CENTRO DE PRODUCCIÓN - RESTAURANTES',
    'Compras':                       'COMPRAS',
    'Contabilidad':                  'CONTABILIDAD',
    'Dirección Comercial':           'DIRECCIÓN COMERCIAL',
    'Eventos':                       'EVENTOS',
    'Gerencia Financiera':           'GERENCIA FINANCIERA',
    'Informática':                   'INFORMÁTICA',
    'La Libertad':                   'RESTAURANTE LA LIBERTAD',
    'Logística':                     'LOGÍSTICA',
    'Mantenimiento':                 'MANTENIMIENTO',
    'Mansión (Casa Guirola)':        'RESTAURANTE MANSIÓN',
    'Mercadeo':                      'MERCADEO',
    'Montaña (Huizúcar)':            'RESTAURANTE MONTAÑA',
    'Operaciones':                   'OPERACIONES',
    'Pico (Opico)':                  'RESTAURANTE OPICO',
    'Planta Sivar':                  'PLANTA SIVAR',
    'Planta Zona Rosa':              'PLANTA ZONA ROSA',
    'Producción':                    'PRODUCCIÓN',
    'Recursos Humanos':              'RECURSOS HUMANOS',
    'Redes Sociales':                'REDES SOCIALES',
    'Santa Elena':                   'RESTAURANTE SANTA ELENA',
    'Venecia (Paseo Venecia)':       'RESTAURANTE PASEO VENECIA',
    'Ventas':                        'VENTAS',
    'Zona Rosa':                     'RESTAURANTE ZONA ROSA',
    'Administrativo Mercadeo':       'ADMINISTRATIVO MERCADEO',
  };

  // Construir lookup nombre → id (case-insensitive)
  const deptoByNombre = {};
  deptos.forEach(d => { deptoByNombre[d.nombre.toLowerCase()] = d.id; });

  // 4. Procesar
  let actualizadas = 0, sinMapa = 0;
  for (const p of plazasSin) {
    const nombreDpto = MAPA[p.sucursal_unidad];
    if (nombreDpto === undefined) {
      console.log(`  ⚠ Sin mapa para sucursal_unidad: "${p.sucursal_unidad}" → ${p.codigo}`);
      sinMapa++;
      continue;
    }
    if (nombreDpto === null) {
      console.log(`  — ${p.codigo} | ${p.sucursal_unidad} → sin departamento (Casa Matriz OK)`);
      continue;
    }

    const dptoId = deptoByNombre[nombreDpto.toLowerCase()];
    if (!dptoId) {
      console.log(`  ⚠ Departamento no encontrado en DB: "${nombreDpto}" → ${p.codigo}`);
      sinMapa++;
      continue;
    }

    console.log(`  [${APPLY ? 'UPD' : 'DRY'}] ${p.codigo} | ${p.sucursal_unidad} → ${nombreDpto} (id=${dptoId})`);
    if (APPLY) {
      await pg.query(`UPDATE plazas SET departamento_id = $1, updated_at = NOW() WHERE id = $2`, [dptoId, p.id]);
    }
    actualizadas++;
  }

  console.log(`\nRESUMEN:`);
  console.log(`  Plazas a actualizar:       ${actualizadas}`);
  console.log(`  Sin mapeo / no encontrado: ${sinMapa}`);
  if (!APPLY) console.log('\n→ Ejecuta con --apply para aplicar.');
  else console.log('\n✅ Listo.');

  await pg.end();
}

main().catch(e => { console.error('Error:', e.message); process.exit(1); });
