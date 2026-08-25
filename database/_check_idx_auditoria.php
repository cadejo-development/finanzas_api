<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pdo = DB::connection('compras')->getPdo();

// Verificar si el índice nuevo existe
$idx = $pdo->query("
    SELECT indexname, indexdef
    FROM pg_indexes
    WHERE tablename = 'auditoria_items'
    AND schemaname = 'public'
    ORDER BY indexname
")->fetchAll(PDO::FETCH_ASSOC);

echo "=== Índices en auditoria_items ===\n";
foreach ($idx as $row) {
    echo "  [{$row['indexname']}]\n    {$row['indexdef']}\n";
}

// Verificar también si aún existe el constraint viejo
$con = $pdo->query("
    SELECT conname, contype
    FROM pg_constraint
    WHERE conrelid = 'auditoria_items'::regclass
    ORDER BY conname
")->fetchAll(PDO::FETCH_ASSOC);

echo "\n=== Constraints en auditoria_items ===\n";
foreach ($con as $row) {
    echo "  [{$row['conname']}] tipo={$row['contype']}\n";
}
