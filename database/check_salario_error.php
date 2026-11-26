<?php
// Revisar error de updateSalario en rrhh.error_logs
require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$logs = DB::connection('rrhh')->table('error_logs')
    ->where(function($q) {
        $q->where('funcion', 'updateSalario')
          ->orWhere('url', 'like', '%/salario%');
    })
    ->orderByDesc('created_at')
    ->limit(5)
    ->get(['funcion','mensaje','tipo_excepcion','codigo_http','created_at','url']);

if ($logs->isEmpty()) {
    echo "Sin errores de updateSalario en error_logs\n";
} else {
    foreach ($logs as $l) {
        echo $l->created_at . ' | ' . $l->funcion . ' | HTTP' . $l->codigo_http . "\n";
        echo 'Tipo: ' . $l->tipo_excepcion . "\n";
        echo 'Msg: ' . $l->mensaje . "\n";
        echo 'URL: ' . $l->url . "\n---\n";
    }
}
