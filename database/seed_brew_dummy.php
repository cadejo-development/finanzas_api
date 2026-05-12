<?php
/**
 * Seed de data dummy para el módulo de producción cervecera.
 * Ejecutar: php database/seed_brew_dummy.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = \DB::connection('compras');

echo "=== SEED BREW DUMMY ===\n\n";

// ── Limpiar tablas en orden ───────────────────────────────────────────────────
$tablas = [
    'brew_lote_llenado_barriles', 'brew_lote_llenado_botellas',
    'brew_lote_ferm_seguimiento', 'brew_lote_fermentacion',
    'brew_lote_filtracion_corridas', 'brew_lote_filtracion',
    'brew_lote_boil_pasos', 'brew_lote_macerado_pasos', 'brew_lote_coccion',
    'brew_lotes',
    'brew_receta_boil_pasos', 'brew_receta_macerado_pasos',
    'brew_receta_levaduras', 'brew_receta_minerales',
    'brew_receta_lupulos', 'brew_receta_maltas',
    'brew_recetas',
];
foreach ($tablas as $t) {
    $db->statement("TRUNCATE TABLE {$t} RESTART IDENTITY CASCADE");
}
echo "Tablas limpiadas.\n\n";

$now = now()->toDateTimeString();

// ══════════════════════════════════════════════════════════════════════════════
// RECETAS
// ══════════════════════════════════════════════════════════════════════════════
$recetas = [
    [
        'nombre' => 'Cadejo Roja',
        'estilo' => 'Irish Red Ale',
        'codigo' => 'ROJA',
        'vol_preboil'  => 320, 'vol_postboil' => 280, 'vol_bbt' => 265,
        'og' => 1.052, 'fg' => 1.013, 'abv' => 5.1, 'ibu' => 22, 'srm' => 18,
        'eficiencia_macerado' => 74, 'dias_ferm' => 14,
        'notas' => 'Clásica de la línea. Color rojizo, caramelo y toffe suave. Baja amargor.',
        'activa' => true, 'created_at' => $now, 'updated_at' => $now,
    ],
    [
        'nombre' => 'Hija de Pooh',
        'estilo' => 'Honey Wheat Ale',
        'codigo' => 'HDP',
        'vol_preboil'  => 320, 'vol_postboil' => 280, 'vol_bbt' => 265,
        'og' => 1.055, 'fg' => 1.010, 'abv' => 5.9, 'ibu' => 14, 'srm' => 5,
        'eficiencia_macerado' => 75, 'dias_ferm' => 12,
        'notas' => 'Miel local adicionada en flameout. Trigo 30%, suave y fácil de tomar.',
        'activa' => true, 'created_at' => $now, 'updated_at' => $now,
    ],
    [
        'nombre' => 'Mera Belga',
        'estilo' => 'Belgian Tripel',
        'codigo' => 'BELGA',
        'vol_preboil'  => 320, 'vol_postboil' => 275, 'vol_bbt' => 260,
        'og' => 1.082, 'fg' => 1.010, 'abv' => 9.4, 'ibu' => 32, 'srm' => 7,
        'eficiencia_macerado' => 70, 'dias_ferm' => 21,
        'notas' => 'Levadura belga WY3787. Ésteres frutales, picante. Carbonatación alta 3.2 vol.',
        'activa' => true, 'created_at' => $now, 'updated_at' => $now,
    ],
];

$recetaIds = [];
foreach ($recetas as $r) {
    $id = $db->table('brew_recetas')->insertGetId($r);
    $recetaIds[$r['codigo']] = $id;
    echo "Receta: {$r['nombre']} [ID {$id}]\n";
}

// ── Maltas ────────────────────────────────────────────────────────────────────
$maltasPorReceta = [
    'ROJA' => [
        ['nombre' => 'Pale Ale Malt (Bestmalz)', 'cantidad_kg' => 48.0, 'lovibond' => 3.5,  'proveedor' => 'Bestmalz'],
        ['nombre' => 'Crystal 40L',              'cantidad_kg' => 10.0, 'lovibond' => 40.0, 'proveedor' => 'Briess'],
        ['nombre' => 'Crystal 80L',              'cantidad_kg' => 6.0,  'lovibond' => 80.0, 'proveedor' => 'Briess'],
        ['nombre' => 'Chocolate Malt',           'cantidad_kg' => 1.5,  'lovibond' => 400.0,'proveedor' => 'Briess'],
        ['nombre' => 'Carapils',                 'cantidad_kg' => 3.0,  'lovibond' => 1.5,  'proveedor' => 'Weyermann'],
    ],
    'HDP' => [
        ['nombre' => 'Pale Ale Malt (Bestmalz)', 'cantidad_kg' => 44.0, 'lovibond' => 3.5,  'proveedor' => 'Bestmalz'],
        ['nombre' => 'Trigo Malteado',           'cantidad_kg' => 18.0, 'lovibond' => 2.0,  'proveedor' => 'Bestmalz'],
        ['nombre' => 'Miel de abeja (flameout)', 'cantidad_kg' => 8.0,  'lovibond' => 3.0,  'proveedor' => 'Apícola Nacional'],
        ['nombre' => 'Carapils',                 'cantidad_kg' => 2.0,  'lovibond' => 1.5,  'proveedor' => 'Weyermann'],
    ],
    'BELGA' => [
        ['nombre' => 'Pilsner Malt (Bestmalz)',  'cantidad_kg' => 55.0, 'lovibond' => 1.6,  'proveedor' => 'Bestmalz'],
        ['nombre' => 'Azúcar Candi Belga Clara', 'cantidad_kg' => 8.0,  'lovibond' => 2.0,  'proveedor' => 'Belga Import'],
        ['nombre' => 'Aromatic Malt',            'cantidad_kg' => 3.0,  'lovibond' => 26.0, 'proveedor' => 'Dingemans'],
        ['nombre' => 'Caravienne',               'cantidad_kg' => 2.0,  'lovibond' => 22.0, 'proveedor' => 'Dingemans'],
    ],
];

foreach ($maltasPorReceta as $codigo => $maltas) {
    $rid = $recetaIds[$codigo];
    foreach ($maltas as $i => $m) {
        $db->table('brew_receta_maltas')->insert(array_merge($m, [
            'brew_receta_id' => $rid, 'orden' => $i,
            'created_at' => $now, 'updated_at' => $now,
        ]));
    }
}
echo "Maltas insertadas.\n";

// ── Lúpulos ───────────────────────────────────────────────────────────────────
$lupulosPorReceta = [
    'ROJA' => [
        ['nombre' => 'Magnum',           'cantidad_g' => 120, 'alpha' => 14.0, 'uso' => 'Boil',      'tiempo_min' => 60],
        ['nombre' => 'East Kent Goldings','cantidad_g' => 180, 'alpha' => 5.0,  'uso' => 'Boil',      'tiempo_min' => 15],
        ['nombre' => 'Fuggles',           'cantidad_g' => 100, 'alpha' => 4.5,  'uso' => 'Whirlpool', 'tiempo_min' => 10],
    ],
    'HDP' => [
        ['nombre' => 'Hallertau Mittelfrüh','cantidad_g' => 150, 'alpha' => 3.5, 'uso' => 'Boil', 'tiempo_min' => 60],
        ['nombre' => 'Saaz',                'cantidad_g' => 100, 'alpha' => 3.0, 'uso' => 'Boil', 'tiempo_min' => 10],
    ],
    'BELGA' => [
        ['nombre' => 'Styrian Goldings', 'cantidad_g' => 200, 'alpha' => 5.5, 'uso' => 'Boil',      'tiempo_min' => 60],
        ['nombre' => 'Saaz',             'cantidad_g' => 150, 'alpha' => 3.0, 'uso' => 'Boil',      'tiempo_min' => 20],
        ['nombre' => 'Styrian Goldings', 'cantidad_g' => 100, 'alpha' => 5.5, 'uso' => 'Whirlpool', 'tiempo_min' => 10],
    ],
];
foreach ($lupulosPorReceta as $codigo => $lupulos) {
    $rid = $recetaIds[$codigo];
    foreach ($lupulos as $i => $l) {
        $db->table('brew_receta_lupulos')->insert(array_merge($l, [
            'brew_receta_id' => $rid, 'orden' => $i,
            'created_at' => $now, 'updated_at' => $now,
        ]));
    }
}
echo "Lúpulos insertados.\n";

// ── Minerales ─────────────────────────────────────────────────────────────────
$mineralesPorReceta = [
    'ROJA' => [
        ['nombre' => 'Cloruro de Calcio',         'cantidad_g' => 9.0,  'fase' => 'Macerado'],
        ['nombre' => 'Sulfato de Calcio (Yeso)',  'cantidad_g' => 6.0,  'fase' => 'Macerado'],
        ['nombre' => 'Bicarbonato de Sodio',      'cantidad_g' => 3.0,  'fase' => 'Macerado'],
        ['nombre' => 'Ácido Láctico 88%',         'cantidad_g' => 2.0,  'fase' => 'Macerado'],
    ],
    'HDP' => [
        ['nombre' => 'Cloruro de Calcio',         'cantidad_g' => 8.0,  'fase' => 'Macerado'],
        ['nombre' => 'Sulfato de Calcio (Yeso)',  'cantidad_g' => 4.0,  'fase' => 'Macerado'],
        ['nombre' => 'Ácido Láctico 88%',         'cantidad_g' => 1.5,  'fase' => 'Macerado'],
    ],
    'BELGA' => [
        ['nombre' => 'Sulfato de Calcio (Yeso)',  'cantidad_g' => 5.0,  'fase' => 'Macerado'],
        ['nombre' => 'Cloruro de Calcio',         'cantidad_g' => 4.0,  'fase' => 'Macerado'],
        ['nombre' => 'Ácido Láctico 88%',         'cantidad_g' => 2.0,  'fase' => 'Macerado'],
    ],
];
foreach ($mineralesPorReceta as $codigo => $minerales) {
    $rid = $recetaIds[$codigo];
    foreach ($minerales as $i => $m) {
        $db->table('brew_receta_minerales')->insert(array_merge($m, [
            'brew_receta_id' => $rid, 'orden' => $i,
            'created_at' => $now, 'updated_at' => $now,
        ]));
    }
}
echo "Minerales insertados.\n";

// ── Levaduras ─────────────────────────────────────────────────────────────────
$levadurasPorReceta = [
    'ROJA'  => [['nombre' => 'Safale S-04',          'codigo' => 'S-04',    'proveedor' => 'Fermentis', 'cantidad_g' => 500, 'temp_min' => 17.0, 'temp_max' => 20.0]],
    'HDP'   => [['nombre' => 'Safale WB-06',          'codigo' => 'WB-06',   'proveedor' => 'Fermentis', 'cantidad_g' => 400, 'temp_min' => 18.0, 'temp_max' => 24.0]],
    'BELGA' => [['nombre' => 'Wyeast 3787 Trappist High Gravity', 'codigo' => 'WY3787', 'proveedor' => 'Wyeast', 'cantidad_g' => 500, 'temp_min' => 18.0, 'temp_max' => 26.0]],
];
foreach ($levadurasPorReceta as $codigo => $levs) {
    $rid = $recetaIds[$codigo];
    foreach ($levs as $lv) {
        $db->table('brew_receta_levaduras')->insert(array_merge($lv, [
            'brew_receta_id' => $rid, 'created_at' => $now, 'updated_at' => $now,
        ]));
    }
}
echo "Levaduras insertadas.\n";

// ── Pasos macerado ────────────────────────────────────────────────────────────
$maceradoPorReceta = [
    'ROJA'  => [
        ['nombre' => 'Proteica',       'temp_objetivo' => 52.0, 'tiempo_min' => 15],
        ['nombre' => 'Sacarificación', 'temp_objetivo' => 67.0, 'tiempo_min' => 60],
        ['nombre' => 'Mash-out',       'temp_objetivo' => 76.0, 'tiempo_min' => 10],
    ],
    'HDP'   => [
        ['nombre' => 'Sacarificación', 'temp_objetivo' => 65.0, 'tiempo_min' => 60],
        ['nombre' => 'Mash-out',       'temp_objetivo' => 76.0, 'tiempo_min' => 10],
    ],
    'BELGA' => [
        ['nombre' => 'Proteica',       'temp_objetivo' => 50.0, 'tiempo_min' => 15],
        ['nombre' => 'Sacarificación', 'temp_objetivo' => 65.0, 'tiempo_min' => 75],
        ['nombre' => 'Mash-out',       'temp_objetivo' => 76.0, 'tiempo_min' => 10],
    ],
];
foreach ($maceradoPorReceta as $codigo => $pasos) {
    $rid = $recetaIds[$codigo];
    foreach ($pasos as $i => $p) {
        $db->table('brew_receta_macerado_pasos')->insert(array_merge($p, [
            'brew_receta_id' => $rid, 'orden' => $i,
            'created_at' => $now, 'updated_at' => $now,
        ]));
    }
}
echo "Pasos de macerado insertados.\n";

// ── Pasos boil ────────────────────────────────────────────────────────────────
$boilPorReceta = [
    'ROJA'  => [
        ['descripcion' => 'Inicio Boil — Magnum 120g',              'tiempo_min' => 60],
        ['descripcion' => 'Min 15 — East Kent Goldings 180g + Whirlfloc', 'tiempo_min' => 15],
        ['descripcion' => 'Flameout — Fuggles 100g Whirlpool 10min','tiempo_min' => null],
        ['descripcion' => 'Enfriar a 18°C — pitch S-04',            'tiempo_min' => null],
    ],
    'HDP'   => [
        ['descripcion' => 'Inicio Boil — Hallertau 150g',           'tiempo_min' => 60],
        ['descripcion' => 'Min 10 — Saaz 100g + Whirlfloc',         'tiempo_min' => 10],
        ['descripcion' => 'Flameout — Miel 8kg (agitar hasta disolver)', 'tiempo_min' => null],
        ['descripcion' => 'Enfriar a 20°C — pitch WB-06',           'tiempo_min' => null],
    ],
    'BELGA' => [
        ['descripcion' => 'Inicio Boil — Styrian Goldings 200g',    'tiempo_min' => 60],
        ['descripcion' => 'Min 20 — Saaz 150g',                     'tiempo_min' => 20],
        ['descripcion' => 'Min 10 — Whirlfloc',                     'tiempo_min' => 10],
        ['descripcion' => 'Flameout — Styrian 100g Whirlpool',      'tiempo_min' => null],
        ['descripcion' => 'Enfriar a 20°C — pitch WY3787',          'tiempo_min' => null],
    ],
];
foreach ($boilPorReceta as $codigo => $pasos) {
    $rid = $recetaIds[$codigo];
    foreach ($pasos as $i => $p) {
        $db->table('brew_receta_boil_pasos')->insert(array_merge($p, [
            'brew_receta_id' => $rid, 'orden' => $i,
            'created_at' => $now, 'updated_at' => $now,
        ]));
    }
}
echo "Pasos de boil insertados.\n\n";

// ══════════════════════════════════════════════════════════════════════════════
// LOTES
// ══════════════════════════════════════════════════════════════════════════════

// Lote 1: Cadejo Roja completo (todo lleno)
$lote1 = $db->table('brew_lotes')->insertGetId([
    'brew_receta_id' => $recetaIds['ROJA'],
    'codigo_lote'    => 'CAD-ROJA-2603-01',
    'fecha_coccion'  => '2026-03-18',
    'estado'         => 'completo',
    'cervecero'      => 'Carlos Henríquez',
    'notas'          => 'Primer batch Roja temporada 2026. Sin problemas destacables.',
    'created_at'     => '2026-03-18 06:00:00',
    'updated_at'     => '2026-04-05 14:00:00',
]);

// Cocción lote 1
$db->table('brew_lote_coccion')->insert([
    'brew_lote_id'      => $lote1,
    'og_real'           => 1.051,
    'vol_preboil_real'  => 319.0,
    'vol_postboil_real' => 278.5,
    'temp_mash_real'    => 67.0,
    'tiempo_boil_min'   => 61,
    'notas'             => 'Mash estable. OG real muy cercana a objetivo.',
    'created_at'        => $now, 'updated_at' => $now,
]);

// Pasos macerado lote 1
foreach ([
    ['orden'=>0,'nombre'=>'Proteica',       'temp_objetivo'=>52.0,'temp_real'=>52.5,'tiempo_min'=>15,'hora_inicio'=>'06:20','hora_fin'=>'06:35'],
    ['orden'=>1,'nombre'=>'Sacarificación', 'temp_objetivo'=>67.0,'temp_real'=>67.0,'tiempo_min'=>60,'hora_inicio'=>'06:40','hora_fin'=>'07:40'],
    ['orden'=>2,'nombre'=>'Mash-out',       'temp_objetivo'=>76.0,'temp_real'=>76.3,'tiempo_min'=>10,'hora_inicio'=>'07:45','hora_fin'=>'07:55'],
] as $p) {
    $db->table('brew_lote_macerado_pasos')->insert(array_merge($p,['brew_lote_id'=>$lote1,'created_at'=>$now,'updated_at'=>$now]));
}

// Pasos boil lote 1
foreach ([
    ['orden'=>0,'descripcion'=>'Inicio Boil — Magnum 120g',              'tiempo_min'=>60,  'hora'=>'08:30','completado'=>true],
    ['orden'=>1,'descripcion'=>'Min 15 — East Kent Goldings 180g + Whirlfloc','tiempo_min'=>15,'hora'=>'09:15','completado'=>true],
    ['orden'=>2,'descripcion'=>'Flameout — Fuggles 100g Whirlpool 10min','tiempo_min'=>null,'hora'=>'09:32','completado'=>true],
    ['orden'=>3,'descripcion'=>'Enfriar a 18°C — pitch S-04',            'tiempo_min'=>null,'hora'=>'10:55','completado'=>true],
] as $p) {
    $db->table('brew_lote_boil_pasos')->insert(array_merge($p,['brew_lote_id'=>$lote1,'created_at'=>$now,'updated_at'=>$now]));
}

// Filtración lote 1
$db->table('brew_lote_filtracion')->insert([
    'brew_lote_id'   => $lote1,
    'vol_bbt_real'   => 263.0,
    'og_bbt'         => 1.050,
    'temp_transfer'  => 18.0,
    'num_corridas'   => 2,
    'notas'          => '2 corridas sin inconvenientes.',
    'created_at'     => $now, 'updated_at' => $now,
]);
$db->table('brew_lote_filtracion_corridas')->insert([
    ['brew_lote_id'=>$lote1,'numero_corrida'=>1,'vol_litros'=>243.0,'densidad'=>1.052,'hora'=>'12:00','notas'=>'Caudal normal','created_at'=>$now,'updated_at'=>$now],
    ['brew_lote_id'=>$lote1,'numero_corrida'=>2,'vol_litros'=>20.0, 'densidad'=>1.015,'hora'=>'14:15','notas'=>'Sparging final','created_at'=>$now,'updated_at'=>$now],
]);

// Fermentación pitch lote 1
$db->table('brew_lote_fermentacion')->insert([
    'brew_lote_id'        => $lote1,
    'fecha_pitch'         => '2026-03-18',
    'temp_pitch'          => 18.0,
    'og_pitch'            => 1.051,
    'vol_pitch'           => 263.0,
    'levadura_nombre'     => 'Safale S-04',
    'levadura_cantidad_g' => 500,
    'notas'               => 'Pitch a las 15:30h. Actividad visible a las 20h.',
    'created_at'          => $now, 'updated_at' => $now,
]);

// Seguimiento fermentación lote 1 — Roja 14 días
foreach ([
    [1,'2026-03-18',1.051,18.0,5.30,'Pitch. Sin actividad aún.'],
    [2,'2026-03-19',1.042,18.5,5.10,'Actividad moderada. Espuma roja visible.'],
    [3,'2026-03-20',1.031,19.0,4.90,'Fermentación activa.'],
    [4,'2026-03-21',1.022,19.2,4.75,'Actividad alta.'],
    [5,'2026-03-22',1.018,19.0,4.65,'Bajando velocidad.'],
    [6,'2026-03-23',1.015,18.8,4.58,'Aroma a caramelo presente.'],
    [7,'2026-03-24',1.014,18.5,4.52,'Estabilizando.'],
    [8,'2026-03-25',1.013,18.5,4.50,''],
    [9,'2026-03-26',1.013,18.2,4.48,''],
    [10,'2026-03-27',1.013,18.0,4.46,'FG alcanzada.'],
    [11,'2026-03-28',1.013,18.0,4.45,'Iniciando cold crash.'],
    [12,'2026-03-29',1.013,5.0, 4.43,'Cold crash a 4°C.'],
    [13,'2026-03-30',1.013,4.0, 4.42,''],
    [14,'2026-03-31',1.013,4.0, 4.41,'Lista para embotellar.'],
] as [$dia,$fecha,$grav,$temp,$ph,$notas]) {
    $db->table('brew_lote_ferm_seguimiento')->insert([
        'brew_lote_id'=>$lote1,'dia'=>$dia,'fecha'=>$fecha,
        'gravedad'=>$grav,'temp'=>$temp,'ph'=>$ph,'notas'=>$notas,
        'created_at'=>$now,'updated_at'=>$now,
    ]);
}

$db->table('brew_lote_llenado_botellas')->insert([
    'brew_lote_id'=>$lote1,'fecha'=>'2026-04-01',
    'vol_inicio'=>260.0,'vol_fin'=>3.5,'botellas_buenas'=>747,'botellas_rotas'=>5,
    'fg_real'=>1.013,'co2_vol'=>2.3,'notas'=>'747 botellas 330ml. Rendimiento excelente.',
    'created_at'=>$now,'updated_at'=>$now,
]);
$db->table('brew_lote_llenado_barriles')->insert([
    'brew_lote_id'=>$lote1,'fecha'=>'2026-04-01',
    'barriles_6th'=>0,'barriles_half'=>0,'vol_total_barriles'=>0,
    'fg_real'=>null,'co2_psi'=>null,'notas'=>'100% embotellado.',
    'created_at'=>$now,'updated_at'=>$now,
]);

echo "Lote 1 (Cadejo Roja completo): OK\n";

// ─────────────────────────────────────────────────────────────────────────────
// Lote 2: Hija de Pooh — seguimiento fermentación día 8 de 12
// ─────────────────────────────────────────────────────────────────────────────
$lote2 = $db->table('brew_lotes')->insertGetId([
    'brew_receta_id' => $recetaIds['HDP'],
    'codigo_lote'    => 'CAD-HDP-2604-01',
    'fecha_coccion'  => '2026-04-22',
    'estado'         => 'seguimiento',
    'cervecero'      => 'Rodrigo Castellanos',
    'notas'          => 'Miel cosecha local Chalatenango. Aroma floral intenso.',
    'created_at'     => '2026-04-22 05:30:00',
    'updated_at'     => '2026-04-30 08:00:00',
]);
$db->table('brew_lote_coccion')->insert([
    'brew_lote_id'=>$lote2,'og_real'=>1.054,'vol_preboil_real'=>319.0,
    'vol_postboil_real'=>278.0,'temp_mash_real'=>65.2,'tiempo_boil_min'=>61,
    'notas'=>'Miel agregada en flameout. Olor dulce increíble.',
    'created_at'=>$now,'updated_at'=>$now,
]);
foreach ([
    ['orden'=>0,'nombre'=>'Sacarificación','temp_objetivo'=>65.0,'temp_real'=>65.2,'tiempo_min'=>60,'hora_inicio'=>'06:00','hora_fin'=>'07:00'],
    ['orden'=>1,'nombre'=>'Mash-out','temp_objetivo'=>76.0,'temp_real'=>76.0,'tiempo_min'=>10,'hora_inicio'=>'07:05','hora_fin'=>'07:15'],
] as $p) {
    $db->table('brew_lote_macerado_pasos')->insert(array_merge($p,['brew_lote_id'=>$lote2,'created_at'=>$now,'updated_at'=>$now]));
}
foreach ([
    ['orden'=>0,'descripcion'=>'Inicio Boil — Hallertau 150g','tiempo_min'=>60,'hora'=>'08:00','completado'=>true],
    ['orden'=>1,'descripcion'=>'Min 10 — Saaz 100g + Whirlfloc','tiempo_min'=>10,'hora'=>'09:00','completado'=>true],
    ['orden'=>2,'descripcion'=>'Flameout — Miel 8kg (agitar hasta disolver)','tiempo_min'=>null,'hora'=>'09:12','completado'=>true],
    ['orden'=>3,'descripcion'=>'Enfriar a 20°C — pitch WB-06','tiempo_min'=>null,'hora'=>'10:45','completado'=>true],
] as $p) {
    $db->table('brew_lote_boil_pasos')->insert(array_merge($p,['brew_lote_id'=>$lote2,'created_at'=>$now,'updated_at'=>$now]));
}
$db->table('brew_lote_filtracion')->insert([
    'brew_lote_id'=>$lote2,'vol_bbt_real'=>263.0,'og_bbt'=>1.053,
    'temp_transfer'=>20.0,'num_corridas'=>1,'notas'=>'Filtración muy limpia.',
    'created_at'=>$now,'updated_at'=>$now,
]);
$db->table('brew_lote_filtracion_corridas')->insert([
    ['brew_lote_id'=>$lote2,'numero_corrida'=>1,'vol_litros'=>263.0,'densidad'=>1.053,'hora'=>'12:00','notas'=>'','created_at'=>$now,'updated_at'=>$now],
]);
$db->table('brew_lote_fermentacion')->insert([
    'brew_lote_id'=>$lote2,'fecha_pitch'=>'2026-04-22','temp_pitch'=>20.0,
    'og_pitch'=>1.054,'vol_pitch'=>263.0,'levadura_nombre'=>'Safale WB-06',
    'levadura_cantidad_g'=>400,'notas'=>'Fermentación de trigo, espuma alta esperada.',
    'created_at'=>$now,'updated_at'=>$now,
]);
foreach ([
    [1,'2026-04-22',1.054,20.0,5.20,'Pitch. Sin actividad aún.'],
    [2,'2026-04-23',1.044,21.0,5.00,'Actividad fuerte. Espuma de trigo abundante.'],
    [3,'2026-04-24',1.032,21.5,4.85,'Banana y clavo muy presentes.'],
    [4,'2026-04-25',1.022,21.0,4.72,'Bajando bien.'],
    [5,'2026-04-26',1.016,20.5,4.62,'Dulce y afrutado.'],
    [6,'2026-04-27',1.013,20.0,4.55,'Miel empieza a destacar.'],
    [7,'2026-04-28',1.011,19.5,4.50,''],
    [8,'2026-04-29',1.010,19.0,4.46,'Casi en FG. Aroma floral de miel.'],
] as [$dia,$fecha,$grav,$temp,$ph,$notas]) {
    $db->table('brew_lote_ferm_seguimiento')->insert([
        'brew_lote_id'=>$lote2,'dia'=>$dia,'fecha'=>$fecha,
        'gravedad'=>$grav,'temp'=>$temp,'ph'=>$ph,'notas'=>$notas,
        'created_at'=>$now,'updated_at'=>$now,
    ]);
}
echo "Lote 2 (Hija de Pooh en seguimiento día 8/12): OK\n";

// ─────────────────────────────────────────────────────────────────────────────
// Lote 3: Mera Belga — en filtración
// ─────────────────────────────────────────────────────────────────────────────
$lote3 = $db->table('brew_lotes')->insertGetId([
    'brew_receta_id' => $recetaIds['BELGA'],
    'codigo_lote'    => 'CAD-BELGA-2604-01',
    'fecha_coccion'  => '2026-04-29',
    'estado'         => 'filtracion',
    'cervecero'      => 'Carlos Henríquez',
    'notas'          => 'Primer batch Mera Belga 2026. Levadura de Trappist.',
    'created_at'     => '2026-04-29 06:00:00',
    'updated_at'     => '2026-04-29 15:00:00',
]);
$db->table('brew_lote_coccion')->insert([
    'brew_lote_id'=>$lote3,'og_real'=>1.080,'vol_preboil_real'=>321.0,
    'vol_postboil_real'=>274.0,'temp_mash_real'=>65.0,'tiempo_boil_min'=>60,
    'notas'=>'OG levemente baja. Azúcar candi ayudó a subir.',
    'created_at'=>$now,'updated_at'=>$now,
]);
foreach ([
    ['orden'=>0,'nombre'=>'Proteica','temp_objetivo'=>50.0,'temp_real'=>50.5,'tiempo_min'=>15,'hora_inicio'=>'06:15','hora_fin'=>'06:30'],
    ['orden'=>1,'nombre'=>'Sacarificación','temp_objetivo'=>65.0,'temp_real'=>65.0,'tiempo_min'=>75,'hora_inicio'=>'06:35','hora_fin'=>'07:50'],
    ['orden'=>2,'nombre'=>'Mash-out','temp_objetivo'=>76.0,'temp_real'=>76.2,'tiempo_min'=>10,'hora_inicio'=>'07:55','hora_fin'=>'08:05'],
] as $p) {
    $db->table('brew_lote_macerado_pasos')->insert(array_merge($p,['brew_lote_id'=>$lote3,'created_at'=>$now,'updated_at'=>$now]));
}
foreach ([
    ['orden'=>0,'descripcion'=>'Inicio Boil — Styrian Goldings 200g','tiempo_min'=>60,'hora'=>'08:30','completado'=>true],
    ['orden'=>1,'descripcion'=>'Min 20 — Saaz 150g','tiempo_min'=>20,'hora'=>'09:10','completado'=>true],
    ['orden'=>2,'descripcion'=>'Min 10 — Whirlfloc','tiempo_min'=>10,'hora'=>'09:20','completado'=>true],
    ['orden'=>3,'descripcion'=>'Flameout — Styrian 100g Whirlpool','tiempo_min'=>null,'hora'=>'09:32','completado'=>true],
    ['orden'=>4,'descripcion'=>'Enfriar a 20°C — pitch WY3787','tiempo_min'=>null,'hora'=>'11:10','completado'=>true],
] as $p) {
    $db->table('brew_lote_boil_pasos')->insert(array_merge($p,['brew_lote_id'=>$lote3,'created_at'=>$now,'updated_at'=>$now]));
}
$db->table('brew_lote_filtracion')->insert([
    'brew_lote_id'=>$lote3,'vol_bbt_real'=>259.0,'og_bbt'=>1.079,
    'temp_transfer'=>20.0,'num_corridas'=>1,'notas'=>'',
    'created_at'=>$now,'updated_at'=>$now,
]);
$db->table('brew_lote_filtracion_corridas')->insert([
    ['brew_lote_id'=>$lote3,'numero_corrida'=>1,'vol_litros'=>259.0,'densidad'=>1.079,'hora'=>'13:00','notas'=>'Corrida limpia','created_at'=>$now,'updated_at'=>$now],
]);
echo "Lote 3 (Mera Belga en filtración): OK\n";

// ─────────────────────────────────────────────────────────────────────────────
// Lote 4: Cadejo Roja — recién iniciada cocción (hoy)
// ─────────────────────────────────────────────────────────────────────────────
$lote4 = $db->table('brew_lotes')->insertGetId([
    'brew_receta_id' => $recetaIds['ROJA'],
    'codigo_lote'    => 'CAD-ROJA-2605-01',
    'fecha_coccion'  => '2026-05-06',
    'estado'         => 'coccion',
    'cervecero'      => 'Ana Fuentes',
    'notas'          => 'Segundo batch Roja del año.',
    'created_at'     => '2026-05-06 05:00:00',
    'updated_at'     => '2026-05-06 05:00:00',
]);
$db->table('brew_lote_coccion')->insert([
    'brew_lote_id'=>$lote4,'og_real'=>1.050,'vol_preboil_real'=>320.0,
    'vol_postboil_real'=>279.0,'temp_mash_real'=>67.0,'tiempo_boil_min'=>60,
    'notas'=>'',
    'created_at'=>$now,'updated_at'=>$now,
]);
foreach ([
    ['orden'=>0,'nombre'=>'Proteica','temp_objetivo'=>52.0,'temp_real'=>52.0,'tiempo_min'=>15,'hora_inicio'=>'05:30','hora_fin'=>'05:45'],
    ['orden'=>1,'nombre'=>'Sacarificación','temp_objetivo'=>67.0,'temp_real'=>67.0,'tiempo_min'=>60,'hora_inicio'=>'05:50','hora_fin'=>'06:50'],
    ['orden'=>2,'nombre'=>'Mash-out','temp_objetivo'=>76.0,'temp_real'=>null,'tiempo_min'=>10,'hora_inicio'=>null,'hora_fin'=>null],
] as $p) {
    $db->table('brew_lote_macerado_pasos')->insert(array_merge($p,['brew_lote_id'=>$lote4,'created_at'=>$now,'updated_at'=>$now]));
}
foreach ([
    ['orden'=>0,'descripcion'=>'Inicio Boil — Magnum 120g','tiempo_min'=>60,'hora'=>'07:30','completado'=>true],
    ['orden'=>1,'descripcion'=>'Min 15 — East Kent Goldings 180g + Whirlfloc','tiempo_min'=>15,'hora'=>'08:15','completado'=>true],
    ['orden'=>2,'descripcion'=>'Flameout — Fuggles 100g Whirlpool 10min','tiempo_min'=>null,'hora'=>null,'completado'=>false],
    ['orden'=>3,'descripcion'=>'Enfriar a 18°C — pitch S-04','tiempo_min'=>null,'hora'=>null,'completado'=>false],
] as $p) {
    $db->table('brew_lote_boil_pasos')->insert(array_merge($p,['brew_lote_id'=>$lote4,'created_at'=>$now,'updated_at'=>$now]));
}
echo "Lote 4 (Cadejo Roja en cocción): OK\n";

// ─────────────────────────────────────────────────────────────────────────────
// Lote 5: Hija de Pooh anterior — completo con barriles y botellas
// ─────────────────────────────────────────────────────────────────────────────
$lote5 = $db->table('brew_lotes')->insertGetId([
    'brew_receta_id' => $recetaIds['HDP'],
    'codigo_lote'    => 'CAD-HDP-2602-01',
    'fecha_coccion'  => '2026-02-10',
    'estado'         => 'completo',
    'cervecero'      => 'Rodrigo Castellanos',
    'notas'          => 'Batch especial evento San Valentín. Miel de flores.',
    'created_at'     => '2026-02-10 05:00:00',
    'updated_at'     => '2026-03-05 12:00:00',
]);
$db->table('brew_lote_coccion')->insert([
    'brew_lote_id'=>$lote5,'og_real'=>1.056,'vol_preboil_real'=>320.0,
    'vol_postboil_real'=>279.0,'temp_mash_real'=>65.0,'tiempo_boil_min'=>60,
    'notas'=>'Batch perfecto. Miel local de alta calidad.',
    'created_at'=>$now,'updated_at'=>$now,
]);
foreach ([
    ['orden'=>0,'nombre'=>'Sacarificación','temp_objetivo'=>65.0,'temp_real'=>65.0,'tiempo_min'=>60,'hora_inicio'=>'05:45','hora_fin'=>'06:45'],
    ['orden'=>1,'nombre'=>'Mash-out','temp_objetivo'=>76.0,'temp_real'=>76.0,'tiempo_min'=>10,'hora_inicio'=>'06:50','hora_fin'=>'07:00'],
] as $p) {
    $db->table('brew_lote_macerado_pasos')->insert(array_merge($p,['brew_lote_id'=>$lote5,'created_at'=>$now,'updated_at'=>$now]));
}
foreach ([
    ['orden'=>0,'descripcion'=>'Inicio Boil — Hallertau 150g','tiempo_min'=>60,'hora'=>'07:30','completado'=>true],
    ['orden'=>1,'descripcion'=>'Min 10 — Saaz 100g + Whirlfloc','tiempo_min'=>10,'hora'=>'08:20','completado'=>true],
    ['orden'=>2,'descripcion'=>'Flameout — Miel 8kg','tiempo_min'=>null,'hora'=>'08:32','completado'=>true],
    ['orden'=>3,'descripcion'=>'Enfriar a 20°C — pitch WB-06','tiempo_min'=>null,'hora'=>'10:00','completado'=>true],
] as $p) {
    $db->table('brew_lote_boil_pasos')->insert(array_merge($p,['brew_lote_id'=>$lote5,'created_at'=>$now,'updated_at'=>$now]));
}
$db->table('brew_lote_filtracion')->insert([
    'brew_lote_id'=>$lote5,'vol_bbt_real'=>264.0,'og_bbt'=>1.055,
    'temp_transfer'=>20.0,'num_corridas'=>1,'notas'=>'',
    'created_at'=>$now,'updated_at'=>$now,
]);
$db->table('brew_lote_filtracion_corridas')->insert([
    ['brew_lote_id'=>$lote5,'numero_corrida'=>1,'vol_litros'=>264.0,'densidad'=>1.055,'hora'=>'11:30','notas'=>'','created_at'=>$now,'updated_at'=>$now],
]);
$db->table('brew_lote_fermentacion')->insert([
    'brew_lote_id'=>$lote5,'fecha_pitch'=>'2026-02-10','temp_pitch'=>20.0,
    'og_pitch'=>1.056,'vol_pitch'=>264.0,'levadura_nombre'=>'Safale WB-06',
    'levadura_cantidad_g'=>400,'notas'=>'',
    'created_at'=>$now,'updated_at'=>$now,
]);
foreach ([
    [1,'2026-02-10',1.056,20.0,5.20,''],[2,'2026-02-11',1.045,21.0,5.05,'Mucha espuma.'],
    [3,'2026-02-12',1.033,21.5,4.90,''],[4,'2026-02-13',1.022,21.0,4.78,''],
    [5,'2026-02-14',1.015,20.5,4.65,'San Valentín. Aroma a miel y flores.'],
    [6,'2026-02-15',1.012,20.0,4.55,''],[7,'2026-02-16',1.011,19.5,4.50,''],
    [8,'2026-02-17',1.010,19.0,4.46,''],[9,'2026-02-18',1.010,5.0,4.44,'Cold crash.'],
    [10,'2026-02-19',1.010,4.0,4.42,''],[11,'2026-02-20',1.010,4.0,4.41,''],
    [12,'2026-02-21',1.010,4.0,4.40,'Lista para envasar.'],
] as [$dia,$fecha,$grav,$temp,$ph,$notas]) {
    $db->table('brew_lote_ferm_seguimiento')->insert([
        'brew_lote_id'=>$lote5,'dia'=>$dia,'fecha'=>$fecha,
        'gravedad'=>$grav,'temp'=>$temp,'ph'=>$ph,'notas'=>$notas,
        'created_at'=>$now,'updated_at'=>$now,
    ]);
}
$db->table('brew_lote_llenado_botellas')->insert([
    'brew_lote_id'=>$lote5,'fecha'=>'2026-02-22',
    'vol_inicio'=>160.0,'vol_fin'=>2.5,'botellas_buenas'=>476,'botellas_rotas'=>3,
    'fg_real'=>1.010,'co2_vol'=>2.5,'notas'=>'476 botellas. Carbonatación alta para estilo.',
    'created_at'=>$now,'updated_at'=>$now,
]);
$db->table('brew_lote_llenado_barriles')->insert([
    'brew_lote_id'=>$lote5,'fecha'=>'2026-02-22',
    'barriles_6th'=>4,'barriles_half'=>1,'vol_total_barriles'=>138.0,
    'fg_real'=>1.010,'co2_psi'=>10.0,
    'notas'=>'4 x 1/6 (79.2L) + 1 x 1/2 (58.7L) = 138L para tiendas.',
    'created_at'=>$now,'updated_at'=>$now,
]);
echo "Lote 5 (Hija de Pooh completo — botellas + barriles): OK\n";

echo "\n✓ Seed completo. " . $db->table('brew_lotes')->count() . " lotes, " . $db->table('brew_recetas')->count() . " recetas.\n";
