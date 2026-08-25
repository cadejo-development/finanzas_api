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
$resp = $ctrl->proyeccion($r);
$data = json_decode($resp->getContent(), true);

echo 'success=' . ($data['success'] ? 'true' : 'false') . PHP_EOL;

if (!($data['success'] ?? false)) {
    echo 'ERROR: ' . ($data['message'] ?? json_encode($data)) . PHP_EOL;
    exit(1);
}

echo 'Fs=' . $data['factor_sucursal'] . '  Fe=' . $data['factor_eventos'] . PHP_EOL;
echo 'eventos=' . implode(', ', $data['eventos'] ?? []) . PHP_EOL;
echo 'proyecciones count=' . count($data['proyecciones']) . PHP_EOL;
echo PHP_EOL;

foreach (array_slice($data['proyecciones'], 0, 5) as $p) {
    echo sprintf(
        "%-45s  qty=%5.1f  pedido=%5.1f  [H=%5.1f Fs=%.3f Fp=%.3f Ft=%5.1f Fe=%.3f]\n",
        substr($p['nombre'], 0, 45),
        $p['qty_proyectada'],
        $p['qty_pedido'],
        $p['factores']['H'],
        $p['factores']['Fs'],
        $p['factores']['Fp'],
        $p['factores']['Ft'],
        $p['factores']['Fe']
    );
}
