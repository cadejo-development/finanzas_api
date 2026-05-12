<?php
/**
 * Backfill: genera codigo_origen para recetas tipo 'plato' que no tienen código.
 * Usa la fecha de creación de cada receta para el componente YYMM.
 * Ejecutar: php database/backfill_codigos_recetas.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$categorias = \DB::connection('compras')
    ->table('receta_categorias')
    ->whereNotNull('key')
    ->pluck('key', 'id');

if ($categorias->isEmpty()) {
    echo "No hay categorías con key. ¿Ya corriste la migración?\n";
    exit(1);
}

echo "Categorías con key:\n";
foreach ($categorias as $id => $key) {
    echo "  [{$id}] {$key}\n";
}
echo "\n";

// Recetas tipo plato sin codigo_origen, que tengan categoria con key
$recetasSinCodigo = \DB::connection('compras')
    ->table('recetas as r')
    ->join('receta_categorias as c', 'c.id', '=', 'r.categoria_id')
    ->whereNotNull('c.key')
    ->where('r.activa', true)
    ->where(function ($q) {
        $q->whereNull('r.tipo_receta')->orWhere('r.tipo_receta', 'plato');
    })
    ->where(function ($q) {
        $q->whereNull('r.codigo_origen')->orWhere('r.codigo_origen', '');
    })
    ->orderBy('c.key')
    ->orderBy('r.created_at')
    ->select('r.id', 'r.nombre', 'r.created_at', 'r.categoria_id', 'c.key', 'c.nombre as cat_nombre')
    ->get();

echo "Recetas sin código: {$recetasSinCodigo->count()}\n\n";

if ($recetasSinCodigo->isEmpty()) {
    echo "Nada que backfillear.\n";
    exit(0);
}

// Contador de correlativos por prefijo (CCCC+YY+MM)
$correlativos = [];

// Pre-cargar correlativos máximos ya existentes para no generar duplicados
foreach ($recetasSinCodigo->pluck('key')->unique() as $key) {
    $cccc = str_replace('-', '', $key);
    $existentes = \DB::connection('compras')
        ->table('recetas')
        ->where('codigo_origen', 'ilike', $cccc . '%')
        ->whereNotNull('codigo_origen')
        ->pluck('codigo_origen');

    foreach ($existentes as $cod) {
        if (preg_match('/^(' . preg_quote($cccc, '/') . '\d{4})(\d{2})$/', $cod, $m)) {
            $prefijo = $m[1];
            $num     = (int) $m[2];
            $correlativos[$prefijo] = max($correlativos[$prefijo] ?? 0, $num);
        }
    }
}

$actualizados = 0;
$errores = 0;

foreach ($recetasSinCodigo as $receta) {
    $cccc   = str_replace('-', '', $receta->key);
    $fecha  = new DateTime($receta->created_at);
    $yy     = $fecha->format('y');
    $mm     = $fecha->format('m');
    $prefijo = $cccc . $yy . $mm;

    $correlativos[$prefijo] = ($correlativos[$prefijo] ?? 0) + 1;
    $nn     = str_pad($correlativos[$prefijo], 2, '0', STR_PAD_LEFT);
    $codigo = $prefijo . $nn;

    // Verificar que no exista ya ese código
    $existe = \DB::connection('compras')
        ->table('recetas')
        ->where('codigo_origen', $codigo)
        ->exists();

    if ($existe) {
        echo "  SKIP [{$receta->id}] {$receta->nombre} — código {$codigo} ya existe\n";
        $errores++;
        continue;
    }

    \DB::connection('compras')
        ->table('recetas')
        ->where('id', $receta->id)
        ->update(['codigo_origen' => $codigo]);

    echo "  OK [{$receta->id}] {$receta->nombre} [{$receta->cat_nombre}] → {$codigo}\n";
    $actualizados++;
}

echo "\nBackfill completo: {$actualizados} actualizadas, {$errores} omitidas.\n";
