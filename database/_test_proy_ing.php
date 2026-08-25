<?php
require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$r = app()->make('Illuminate\Http\Request');
$r->merge([
    'sucursal_id'   => 8,
    'desde'         => '2026-07-24',
    'hasta'         => '2026-08-02',
    'categoria_key' => 'platos_fuertes',
]);

$ctrl = new \App\Http\Controllers\Api\Compras\VentasController();
$resp = $ctrl->proyeccionIngredientes($r);
$data = json_decode($resp->getContent(), true);

echo 'success=' . ($data['success'] ? 'true' : 'false') . PHP_EOL;
if (!($data['success'] ?? false)) {
    echo 'ERROR: ' . ($data['message'] ?? json_encode($data)) . PHP_EOL;
    exit(1);
}
echo 'eventos=' . implode(', ', $data['eventos'] ?? []) . PHP_EOL;
echo 'ingredientes count=' . count($data['ingredientes']) . PHP_EOL;
echo PHP_EOL;

$conConteo = array_filter($data['ingredientes'], fn($i) => $i['conteo_fisico'] !== null);
echo 'Con conteo fisico este mes: ' . count($conConteo) . PHP_EOL;
echo PHP_EOL;

foreach (array_slice($data['ingredientes'], 0, 8) as $i) {
    $conteo = $i['conteo_fisico'] !== null ? $i['conteo_fisico'] : '—';
    echo sprintf(
        "%-40s  proy=%7.3f  conteo=%7s  pedir=%7.3f  %s\n",
        substr($i['nombre'], 0, 40),
        $i['qty_proyectada'],
        $conteo,
        $i['total_a_pedir'],
        $i['unidad']
    );
}
