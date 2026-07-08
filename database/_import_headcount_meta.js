/**
 * Importa valores Meta del Excel Comparativo Plantilla → cargo_plazas_autorizadas
 *
 * Uso:
 *   node _import_headcount_meta.js          <- dry run
 *   node _import_headcount_meta.js --apply  <- aplica los cambios
 */

const XLSX       = require('xlsx')
const { Client } = require('pg')

const APPLY = process.argv.includes('--apply')

// Mapeo EXACTO: nombre en Excel → substring a buscar en departamentos.nombre (ILIKE)
const DPTO_MAP = {
  'AE Malcriadas':           'malcriadas',
  'Aeropuerto 1':            'aeropuerto 1',
  'Aeropuerto 2':            'aeropuerto 2',
  'La Libertad':             'la libertad',
  'Mansión (Casa Guirola)':  'mansión',
  'Montaña (Huizúcar)':     'montaña',
  'Opico':                   'opico',
  'Venecia (Paseo Venecia)': 'venecia',
  'Santa Elena':             'santa elena',
  'Zona Rosa':               'restaurante zona rosa',
}

// Mapeo de nombres de cargo en Excel → substring en cargos.nombre
// Para "Gerente de Restaurante" usamos "gerente de sucursal" para evitar el match en "Subgerente"
const CARGO_ALIASES = {
  'Gerente de Restaurante':     'gerente de sucursal',
  'Sub Gerente de Restaurante': 'subgerente de restaurante',
  'SubGerente':                 'subgerente de restaurante',
  'Sub Jefe de Cocina':         'sub jefe de cocina',
  'Host':                       'hostess',
  'Limpieza':                   'encargado de limpieza',
  'Servicios Varios':           'servicios varios',
  'Guardavidas':                'guardavidas',
  'Jardinero':                  'jardinero',
  'Runner':                     'runner',
  'Steward':                    'steward',
  'Seguridad':                  'seguridad',
  'Supervisor de Cocina':       'supervisor de cocina',
  'Personal de Servicio':       'personal de servicio',
  'Profesional de Servicio':    'profesional de servicio',
}

async function main() {
  const wb   = XLSX.readFile('C:/Users/administrator/Downloads/Catalogo_de_Plazas_Cadejo 1.xlsx')
  const ws   = wb.Sheets['Comparativo Plantilla']
  const rows = XLSX.utils.sheet_to_json(ws, { header: 1 }).slice(1)

  const client = new Client({
    host:     'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
    port:     5432,
    database: 'core_db',
    user:     'cadejo_admin',
    password: 'Holamundo#3..',
    ssl:      { rejectUnauthorized: false },
  })
  await client.connect()

  // Todos los departamentos
  const { rows: dptos } = await client.query('SELECT id, nombre FROM departamentos ORDER BY nombre')

  // Lookup: departamento_id → Map(cargo_nombre_lower → cargo_id)
  // Sólo cargos que tienen plazas activas en ese departamento
  const { rows: plazaCargos } = await client.query(`
    SELECT DISTINCT p.departamento_id, ca.id AS cargo_id, LOWER(ca.nombre) AS cargo_nombre
    FROM plazas p
    JOIN cargos ca ON ca.id = p.cargo_id
    WHERE p.activo = true AND p.departamento_id IS NOT NULL
  `)

  const dptoCargoIndex = {}  // { dept_id: { cargo_nombre_lower: cargo_id } }
  for (const pc of plazaCargos) {
    if (!dptoCargoIndex[pc.departamento_id]) dptoCargoIndex[pc.departamento_id] = {}
    dptoCargoIndex[pc.departamento_id][pc.cargo_nombre] = pc.cargo_id
  }

  function findDpto(excelNombre) {
    const fragment = (DPTO_MAP[excelNombre] ?? excelNombre).toLowerCase()
    return dptos.find(d => d.nombre.toLowerCase().includes(fragment)) ?? null
  }

  function findCargo(dptoId, excelNombre) {
    const alias   = (CARGO_ALIASES[excelNombre] ?? excelNombre).toLowerCase()
    const cargoMap = dptoCargoIndex[dptoId] ?? {}

    // Primero busca coincidencia exacta con el alias
    if (cargoMap[alias] !== undefined) return { id: cargoMap[alias], nombre: alias }

    // Luego substring dentro de los nombres disponibles en ese dpto
    const found = Object.entries(cargoMap).find(([nombre]) => nombre.includes(alias) || alias.includes(nombre))
    if (found) return { id: found[1], nombre: found[0] }

    return null
  }

  const errors  = []
  const updates = []

  for (const row of rows) {
    const [sucursal, cargo, , meta] = row
    if (!sucursal || !cargo || meta == null) continue

    const dpto  = findDpto(sucursal)
    if (!dpto) { errors.push(`  ❌ DPTO no encontrado: "${sucursal}"`); continue }

    const puest = findCargo(dpto.id, cargo)
    if (!puest) { errors.push(`  ❌ CARGO no encontrado: "${cargo}" en "${dpto.nombre}"`); continue }

    updates.push({ dpto_id: dpto.id, dpto_nombre: dpto.nombre, cargo_id: puest.id, cargo_nombre: puest.nombre, meta })
  }

  console.log(`\n📋  Filas Excel: ${rows.length}  |  Mapeadas: ${updates.length}  |  Errores: ${errors.length}\n`)

  if (errors.length) {
    console.log('── Errores de mapeo ──────────────────────────────────────')
    errors.forEach(e => console.log(e))
    console.log()
  }

  console.log('── Preview de cambios ────────────────────────────────────')
  let nCambia = 0, nNuevo = 0, nIgual = 0
  for (const u of updates) {
    const { rows: cur } = await client.query(
      'SELECT cantidad FROM cargo_plazas_autorizadas WHERE cargo_id = $1 AND departamento_id = $2',
      [u.cargo_id, u.dpto_id]
    )
    const actual = cur[0]?.cantidad
    let tag
    if (actual == null) { tag = '🆕 nuevo';   nNuevo++ }
    else if (u.meta !== Number(actual)) { tag = '⬆️  CAMBIA'; nCambia++ }
    else { tag = '✅ igual'; nIgual++ }

    console.log(`  ${u.dpto_nombre.padEnd(28)} | ${u.cargo_nombre.padEnd(32)} | actual: ${String(actual ?? '—').padEnd(4)} → meta: ${u.meta}  ${tag}`)
  }
  console.log(`\n  Resumen: ${nCambia} cambios, ${nNuevo} nuevos, ${nIgual} sin cambio\n`)

  if (!APPLY) {
    console.log('⚠️  DRY RUN — nada fue modificado. Pasa --apply para aplicar.\n')
    await client.end()
    return
  }

  console.log('🔄  Aplicando cambios...')
  for (const u of updates) {
    await client.query(`
      INSERT INTO cargo_plazas_autorizadas (cargo_id, departamento_id, cantidad, created_at, updated_at)
      VALUES ($1, $2, $3, NOW(), NOW())
      ON CONFLICT (cargo_id, departamento_id) DO UPDATE SET cantidad = EXCLUDED.cantidad, updated_at = NOW()
    `, [u.cargo_id, u.dpto_id, u.meta])
  }
  console.log(`✅  ${updates.length} registros actualizados.\n`)
  await client.end()
}

main().catch(e => { console.error(e); process.exit(1) })
