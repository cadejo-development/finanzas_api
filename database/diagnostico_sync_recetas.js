/**
 * diagnostico_sync_recetas.js
 *
 * DRY-RUN detallado: muestra exactamente qué haría sync_recetas_diario.js
 * sin escribir nada en RDS.
 *
 * Para cada receta con ingredientes diferentes entre Brilo y RDS muestra:
 *   - modificado_localmente=false → SERÍAN sobreescritos
 *   - modificado_localmente=true  → NO serían tocados, pero ves la diferencia
 *
 * Uso:
 *   node diagnostico_sync_recetas.js               (todas las recetas)
 *   node diagnostico_sync_recetas.js --solo-diffs  (solo las que tienen diferencias)
 */

require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });
const sql      = require('mssql');
const { Pool } = require('pg');

const SOLO_DIFFS = process.argv.includes('--solo-diffs');

const SQL_CFG = {
  user: process.env.DB_USERNAME_ORIGEN, password: process.env.DB_PASSWORD_ORIGEN,
  server: process.env.DB_HOST_ORIGEN, port: 2033, database: 'olcomun',
  options: { trustServerCertificate: true, encrypt: false, connectTimeout: 20000 },
};
const PG_CFG = {
  host: process.env.DB_HOST_COMPRAS || process.env.DB_HOST, port: 5432,
  database: process.env.DB_DATABASE_COMPRAS || 'gestion_operaciones_db',
  user: process.env.DB_USERNAME_COMPRAS || process.env.DB_USERNAME,
  password: process.env.DB_PASSWORD_COMPRAS || process.env.DB_PASSWORD,
  ssl: { rejectUnauthorized: false },
};

const PREFIJOS_RECETA = ['PL', 'SUBR', 'PR', 'PRD', 'LLPL', 'HZPL', 'HZPR', 'EVPL', 'VOPL', 'PMPL'];
const esCodReceta = cod => cod && PREFIJOS_RECETA.some(p => String(cod).startsWith(p));

const UNIT_MAP = {
  'ONZAS': 'oz', 'ONZA': 'oz',
  'ONZAS FLUIDAS': 'oz fl', 'ONZA FLUIDA': 'oz fl',
  'UNIDAD': 'u', 'UNIDADES': 'u',
  'LIBRA': 'lb', 'LIBRAS': 'lb',
  'LITRO': 'lt', 'LITROS': 'lt',
  'MILILITRO': 'ml', 'MILILITROS': 'ml',
  'KILOGRAMO': 'kg', 'KILOGRAMOS': 'kg',
  'GRAMO': 'g', 'GRAMOS': 'g',
  'BARRIL': 'barril', 'BOTELLA': 'botella',
  'BOTELLA 0.75 LT': 'botella', 'BOTELLA 0.70 LT': 'botella',
  'PORCION': 'porcion', 'PORCIÓN': 'porcion',
  'REBANADA': 'rebanada', 'CAJA': 'caja', 'PAQUETE': 'paquete',
  'GALON': 'galon', 'GALÓN': 'galon', 'TANDA': 'tanda',
  'BOLSA 2 KG': 'bolsa 2kg', 'BOLSA 1 KG': 'bolsa 1kg', 'BOLSA 5LB': 'bolsa 5lb',
};
const normUnit = s => !s ? 'u' : (UNIT_MAP[s.trim().toUpperCase()] ?? s.trim().toLowerCase().slice(0, 20));

function cantUniAlmacenar(ing) {
  const cantPres = parseFloat(ing.cantidad_pres);
  if (cantPres > 0 && ing.unidad_pres) {
    return { cantidad: cantPres, unidad: normUnit(ing.unidad_pres) };
  }
  return { cantidad: parseFloat(ing.cantidad_base) || 0, unidad: normUnit(ing.unidad_base) };
}

function fmtNum(n) { return n == null ? '—' : parseFloat(n).toPrecision(5).replace(/\.?0+$/, ''); }

async function main() {
  const sqlPool = await sql.connect(SQL_CFG);
  const pgPool  = new Pool(PG_CFG);
  const pg      = await pgPool.connect();

  console.log('════════════════════════════════════════════════════════════════');
  console.log('DIAGNÓSTICO SYNC RECETAS — sin cambios en RDS');
  console.log(`Modo: ${SOLO_DIFFS ? 'solo diferencias' : 'todas las recetas actualizables'}`);
  console.log('════════════════════════════════════════════════════════════════\n');

  try {
    // ── 1. Cargar Brilo ──────────────────────────────────────────────────────
    const { rows: catRows } = await pg.query(`SELECT nombre FROM receta_categorias WHERE activa = true`);
    const catValidasSet = new Set([...catRows.map(r => r.nombre), 'Sub-Recetas', 'Sub-Receta', 'Sub Recetas', 'Platos Sub-Recetas']);

    const bRec = (await sqlPool.request().query(`
      SELECT p.proId, TRIM(p.proCodigo) AS codigo, p.proNombre AS nombre,
             p.proActivo AS activo, cpr.cprNombre AS cat_nombre
      FROM olComun.dbo.Productos p
      INNER JOIN olComun.dbo.CategoriasProductos cpr ON cpr.cprId = p.cprId
      WHERE p.proCodigo IS NOT NULL AND LTRIM(RTRIM(p.proCodigo)) != ''
        AND (p.proCodigo LIKE 'PL%'   OR p.proCodigo LIKE 'SUBR%'
          OR p.proCodigo LIKE 'PR%'   OR p.proCodigo LIKE 'PRD%'
          OR p.proCodigo LIKE 'LLPL%' OR p.proCodigo LIKE 'HZPL%'
          OR p.proCodigo LIKE 'HZPR%' OR p.proCodigo LIKE 'EVPL%'
          OR p.proCodigo LIKE 'VOPL%' OR p.proCodigo LIKE 'PMPL%')
      ORDER BY p.proCodigo
    `)).recordset.filter(r => catValidasSet.has(r.cat_nombre));

    const briloMap = {};
    bRec.forEach(r => { briloMap[r.codigo] = r; });

    const proIds = bRec.map(r => r.proId).join(',');
    const bIng = proIds.length ? (await sqlPool.request().query(`
      SELECT mx.proId AS rec_proId, TRIM(mat.proCodigo) AS ing_codigo,
             mat.proNombre AS ing_nombre,
             mx.mxprCantidad   AS cantidad_base,
             ISNULL(uniBase.uniNombre, 'u') AS unidad_base,
             mx.mxprCantUnidad AS cantidad_pres,
             uniPres.uniNombre AS unidad_pres
      FROM olComun.dbo.MaterialesXProducto mx
      JOIN olComun.dbo.Productos mat ON mat.proId = mx.proIdMaterial
      LEFT JOIN olComun.dbo.Unidades uniBase ON uniBase.uniId = mat.uniId
      LEFT JOIN olComun.dbo.Unidades uniPres ON uniPres.uniId = mx.uniId
      WHERE mx.proId IN (${proIds})
        AND mx.mxprActivo = 1 AND mx.mxprEliminado = 0 AND mx.mxprCantidad > 0
        AND mat.proCodigo IS NOT NULL
      ORDER BY mx.proId, mx.mxprId
    `)).recordset : [];

    const briloIngMap = {};
    bIng.forEach(r => {
      if (!briloIngMap[r.rec_proId]) briloIngMap[r.rec_proId] = [];
      briloIngMap[r.rec_proId].push(r);
    });

    // ── 2. Cargar RDS ────────────────────────────────────────────────────────
    const { rows: rdsRec } = await pg.query(`
      SELECT r.id, r.codigo_origen, r.nombre, r.activa, r.estado_id,
             r.modificado_localmente, r.sincronizado_brilo
      FROM recetas r
      WHERE r.codigo_origen IS NOT NULL AND r.codigo_origen != ''
      ORDER BY r.codigo_origen
    `);
    const rdsMap = {};
    rdsRec.forEach(r => { rdsMap[r.codigo_origen] = r; });

    // Cargar menú de RDS: receta_sucursal activa → saber en qué sucursales está publicada
    const { rows: rsRows } = await pg.query(`
      SELECT receta_id, sucursal_id FROM receta_sucursal WHERE activa = true
    `);
    const rdsSucMap = {}; // receta_id → [ sucursal_id ]
    rsRows.forEach(r => {
      if (!rdsSucMap[r.receta_id]) rdsSucMap[r.receta_id] = [];
      rdsSucMap[r.receta_id].push(r.sucursal_id);
    });

    // Cargar menú de Brilo: ProductoXCocinaXSucRst → en qué sucursales está configurado
    const bMenuRows = (await sqlPool.request().query(`
      SELECT DISTINCT TRIM(p.proCodigo) AS codigo, px.sucId
      FROM olRestaurante.dbo.ProductoXCocinaXSucRst px WITH(NOLOCK)
      JOIN olComun.dbo.Productos p WITH(NOLOCK) ON p.proId = px.proId
      WHERE p.proCodigo IS NOT NULL
    `)).recordset;
    const briloMenuMap = {}; // codigo → Set<sucId>
    bMenuRows.forEach(r => {
      if (!briloMenuMap[r.codigo]) briloMenuMap[r.codigo] = new Set();
      briloMenuMap[r.codigo].add(r.sucId);
    });

    // Ingredientes RDS de todas las recetas que existen en Brilo también
    const recetasEnBrilo = rdsRec.filter(r => briloMap[r.codigo_origen]).map(r => r.id);
    const { rows: rdsIngs } = await pg.query(`
      SELECT ri.receta_id,
             COALESCE(p.codigo, sr.codigo_origen) AS ing_codigo,
             COALESCE(p.nombre, sr.nombre)        AS ing_nombre,
             ri.cantidad_por_plato                AS cantidad,
             ri.unidad,
             CASE WHEN ri.sub_receta_id IS NOT NULL THEN true ELSE false END AS es_sub_receta
      FROM receta_ingredientes ri
      LEFT JOIN productos p  ON p.id  = ri.producto_id
      LEFT JOIN recetas   sr ON sr.id = ri.sub_receta_id
      WHERE ri.receta_id = ANY($1)
    `, [recetasEnBrilo]);

    const rdsIngMap = {};
    // Set de "receta_id:codigo" que son sub_receta → el sync los protege
    const subRecetaProtegidos = new Set();
    rdsIngs.forEach(r => {
      if (!rdsIngMap[r.receta_id]) rdsIngMap[r.receta_id] = [];
      rdsIngMap[r.receta_id].push(r);
      if (r.es_sub_receta) subRecetaProtegidos.add(`${r.receta_id}:${r.ing_codigo}`);
    });

    // Mapa producto_id por codigo (para saber si existe en RDS)
    const { rows: prodRows } = await pg.query(`SELECT id, codigo FROM productos WHERE activo = true`);
    const prodExiste = new Set(prodRows.map(r => r.codigo));

    // ── 3. Analizar ──────────────────────────────────────────────────────────
    const resumen = {
      total_en_brilo:     bRec.length,
      solo_en_brilo:      0,
      en_ambos:           0,
      en_menu_rds:        0,
      mod_local_false:    { sin_diffs: 0, con_diffs: 0 },
      mod_local_true:     { sin_diffs: 0, con_diffs: 0 },
    };

    const recConDiffs   = [];
    const recSinDiffs   = [];

    for (const cod of Object.keys(briloMap)) {
      const b = briloMap[cod];
      const r = rdsMap[cod];
      if (!r) { resumen.solo_en_brilo++; continue; }
      resumen.en_ambos++;

      const bIngs   = (briloIngMap[b.proId] || []);
      const rIngs   = (rdsIngMap[r.id] || []);

      // Calcular lo que el sync ESCRIBIRÍA para cada ingrediente de Brilo
      const briloWrites = [];
      for (const ing of bIngs) {
        const { cantidad, unidad } = cantUniAlmacenar(ing);
        briloWrites.push({
          codigo:      ing.ing_codigo,
          nombre:      ing.ing_nombre,
          brilo_base:  `${fmtNum(ing.cantidad_base)} ${normUnit(ing.unidad_base)}`,
          brilo_pres:  ing.cantidad_pres > 0 && ing.unidad_pres
                         ? `${fmtNum(ing.cantidad_pres)} ${normUnit(ing.unidad_pres)}`
                         : null,
          sync_escribe: `${fmtNum(cantidad)} ${unidad}`,
          en_rds:      false,
          rds_valor:   null,
          existe_prod: prodExiste.has(ing.ing_codigo),
        });
      }

      // Marcar los que ya están en RDS y calcular si difieren
      const rdsSet = {};
      rIngs.forEach(ri => { rdsSet[ri.ing_codigo] = ri; });

      let hayCambios = false;
      for (const bw of briloWrites) {
        const ri = rdsSet[bw.codigo];
        // ¿Es un ingrediente protegido porque en RDS es sub_receta?
        bw.es_sub_receta = subRecetaProtegidos.has(`${r.id}:${bw.codigo}`);
        if (ri) {
          bw.en_rds    = true;
          bw.rds_valor = `${fmtNum(ri.cantidad)} ${ri.unidad}`;
          // Si es sub_receta, el sync lo salta → no cuenta como cambio
          bw.cambia    = !bw.es_sub_receta && (bw.rds_valor !== bw.sync_escribe);
          if (bw.cambia) hayCambios = true;
        } else {
          bw.en_rds  = false;
          // Si es sub_receta no existente como producto, tampoco el sync lo toca
          bw.cambia  = !bw.es_sub_receta;
          if (bw.cambia) hayCambios = true;
        }
      }

      // Ingredientes en RDS que NO están en Brilo (el sync no los toca en 4b)
      const soloEnRds = rIngs.filter(ri => !bIngs.some(b => b.ing_codigo === ri.ing_codigo));

      // Flag activa: ¿cambiaría?
      const nuevaActiva = !b.activo ? false : r.activa;
      const activaCambia = nuevaActiva !== r.activa;
      if (activaCambia) hayCambios = true;

      // Menú: sucursales en RDS (receta_sucursal) y en Brilo (ProductoXCocinaXSucRst)
      const menuRds   = [...(rdsSucMap[r.id]   || [])].sort((a,b) => a-b);
      const menuBrilo = [...(briloMenuMap[cod]  || new Set())].sort((a,b) => a-b);

      const entry = {
        codigo:        cod,
        nombre:        r.nombre,
        mod_local:     r.modificado_localmente,
        activa_rds:    r.activa,
        activa_brilo:  b.activo,
        activa_cambia: activaCambia,
        nueva_activa:  nuevaActiva,
        ing_writes:    briloWrites,
        solo_en_rds:   soloEnRds,
        hay_cambios:   hayCambios,
        menu_rds:      menuRds,
        menu_brilo:    menuBrilo,
        en_menu:       menuRds.length > 0 || menuBrilo.length > 0,
      };

      if (entry.menu_rds.length > 0) resumen.en_menu_rds++;

      if (hayCambios) {
        recConDiffs.push(entry);
        if (r.modificado_localmente) resumen.mod_local_true.con_diffs++;
        else                         resumen.mod_local_false.con_diffs++;
      } else {
        recSinDiffs.push(entry);
        if (r.modificado_localmente) resumen.mod_local_true.sin_diffs++;
        else                         resumen.mod_local_false.sin_diffs++;
      }
    }

    // ── 4. Imprimir ─────────────────────────────────────────────────────────
    const todo = SOLO_DIFFS ? recConDiffs : [...recConDiffs, ...recSinDiffs];

    for (const rec of todo) {
      const protegida = rec.mod_local;
      const accion    = protegida ? '🔒 PROTEGIDA (mod_local=true) — ingredientes NO se tocan' : '⚠️  ACTUALIZABLE (mod_local=false) — ingredientes SE SOBREESCRIBEN';

      const menuRdsStr   = rec.menu_rds.length   ? `suc(${rec.menu_rds.join(',')})` : '—';
      const menuBriloStr = rec.menu_brilo.length ? `suc(${rec.menu_brilo.join(',')})` : '—';
      const menuTag      = rec.menu_rds.length || rec.menu_brilo.length
        ? `📋 MENÚ RDS: ${menuRdsStr}  |  Brilo botones: ${menuBriloStr}` : '';

      console.log(`\n────────────────────────────────────────────────────────────`);
      console.log(`${rec.codigo.padEnd(18)} ${rec.nombre}`);
      console.log(accion);
      if (menuTag) console.log(`  ${menuTag}`);
      if (rec.activa_cambia) {
        console.log(`  ⚡ ACTIVA: ${rec.activa_rds} → ${rec.nueva_activa}  (Brilo=${rec.activa_brilo})`);
      }

      if (rec.ing_writes.length === 0) {
        console.log('  (sin ingredientes en Brilo)');
      } else {
        const header = `${'Ingrediente'.padEnd(35)} ${'RDS actual'.padEnd(18)} ${'Sync escribiría'.padEnd(18)} ${'Base Brilo'.padEnd(18)} ${'Pres. Brilo'.padEnd(15)} ${'¿Cambia?'}`;
        console.log('\n  ' + header);
        console.log('  ' + '─'.repeat(header.length));
        for (const bw of rec.ing_writes) {
          const nombre    = (bw.nombre || bw.codigo).slice(0, 34).padEnd(35);
          const rdsVal    = (bw.rds_valor ?? '(no existe)').padEnd(18);
          const syncVal   = bw.sync_escribe.padEnd(18);
          const base      = bw.brilo_base.padEnd(18);
          const pres      = (bw.brilo_pres ?? '—').padEnd(15);
          const cambia    = bw.es_sub_receta
                            ? '🔗 sub_receta (protegida)'
                            : !bw.en_rds ? '➕ NUEVO'
                            : (bw.cambia ? (protegida ? '≠ (no aplica)' : '⚠️  CAMBIA') : '✓ igual');
          const noProd    = !bw.existe_prod ? ' [sin prod en RDS]' : '';
          console.log(`  ${nombre} ${rdsVal} ${syncVal} ${base} ${pres} ${cambia}${noProd}`);
        }
      }

      if (rec.solo_en_rds.length) {
        console.log(`\n  Ingredientes solo en RDS (el sync los deja intactos):`);
        rec.solo_en_rds.forEach(ri =>
          console.log(`    • ${(ri.ing_nombre || ri.ing_codigo).slice(0, 40).padEnd(40)} ${fmtNum(ri.cantidad)} ${ri.unidad}`)
        );
      }
    }

    // ── 5. Resumen ───────────────────────────────────────────────────────────
    console.log('\n════════════════════════════════════════════════════════════════');
    console.log('RESUMEN');
    console.log(`  Total recetas en Brilo:                     ${resumen.total_en_brilo}`);
    console.log(`  Solo en Brilo (se crearían si se corre):    ${resumen.solo_en_brilo}`);
    console.log(`  En ambos (Brilo + RDS):                     ${resumen.en_ambos}`);
    console.log('');
    console.log(`  En menú (receta_sucursal activa en RDS):    ${resumen.en_menu_rds}`);
    console.log('');
    console.log(`  mod_local=false (actualizables con sync):`);
    console.log(`    Sin diferencias vs Brilo:                 ${resumen.mod_local_false.sin_diffs}`);
    console.log(`    CON diferencias → serían SOBREESCRITAS:   ${resumen.mod_local_false.con_diffs}`);
    console.log('');
    console.log(`  mod_local=true (protegidas del sync):`);
    console.log(`    Sin diferencias vs Brilo:                 ${resumen.mod_local_true.sin_diffs}`);
    console.log(`    CON diferencias vs Brilo (info, no aplica):${resumen.mod_local_true.con_diffs}`);
    console.log('════════════════════════════════════════════════════════════════');

  } finally {
    pg.release();
    await pgPool.end();
    await sqlPool.close();
  }
}

main().catch(err => { console.error('ERROR:', err.message ?? err); process.exit(1); });
