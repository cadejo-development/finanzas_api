<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * php artisan inventario:sync-brilo-stock [sucursal_id]
 *
 * Ejecuta el script Node.js de sync de stock Brilo para cada sucursal que tenga
 * productos marcados como prod_seg = true. Si se pasa un sucursal_id específico,
 * solo sincroniza esa sucursal.
 *
 * Corre automáticamente a las 03:00 AM vía scheduler.
 */
class SyncBriloStockCommand extends Command
{
    protected $signature   = 'inventario:sync-brilo-stock {sucursal_id? : ID de sucursal específica (opcional)}';
    protected $description = 'Sincroniza brilo_stock desde SQL Server para sucursales con productos de seguimiento (prod_seg).';

    // sucursal_id (compras_db) → ubiId en Brilo
    private const SUCURSAL_UBI_MAP = [
         1 => 37,   // REST. ZONA ROSA
         3 => 48,   // REST. LA LIBERTAD
         4 => 51,   // REST. AEROPUERTO #1
         5 => 52,   // REST. AEROPUERTO #2
         7 => 57,   // REST. PASEO VENECIA
         8 => 58,   // REST. SANTA ELENA
         9 => 65,   // REST. HUIZUCAR
        10 => 69,   // REST. OPICO
        11 => 77,   // REST. CASA GUIROLA
        16 => 76,   // REST. MALCRIADAS
    ];

    public function handle(): int
    {
        $specificId = $this->argument('sucursal_id') ? (int) $this->argument('sucursal_id') : null;

        // Determinar sucursales a sincronizar
        $query = DB::connection('compras')
            ->table('inventarios')
            ->where('prod_seg', true)
            ->distinct()
            ->pluck('sucursal_id')
            ->map(fn ($id) => (int) $id);

        if ($specificId) {
            $query = $query->filter(fn ($id) => $id === $specificId);
        }

        $sucursales = $query->filter(fn ($id) => isset(self::SUCURSAL_UBI_MAP[$id]))->values();

        if ($sucursales->isEmpty()) {
            $this->warn('No hay sucursales con prod_seg=true mapeadas en SUCURSAL_UBI_MAP.');
            return 0;
        }

        $this->info('Sincronizando brilo_stock para ' . $sucursales->count() . ' sucursal(es)...');

        $scriptPath = base_path('database/sync_brilo_stock_inventario.js');

        if (!file_exists($scriptPath)) {
            $this->error("Script no encontrado: {$scriptPath}");
            return 1;
        }

        $errores = 0;
        foreach ($sucursales as $sucId) {
            $this->info("  → Sucursal #{$sucId}...");

            $process = new Process(['node', $scriptPath, (string) $sucId]);
            $process->setWorkingDirectory(base_path());
            $process->setTimeout(300);

            $process->run(function ($type, $buffer) {
                if (Process::ERR === $type) {
                    $this->warn('    ' . trim($buffer));
                } else {
                    $this->line('    ' . trim($buffer));
                }
            });

            if (!$process->isSuccessful()) {
                $this->error("  ✗ Error en sucursal #{$sucId}: " . $process->getErrorOutput());
                $errores++;
            } else {
                $this->info("  ✓ Sucursal #{$sucId} sincronizada.");
            }
        }

        if ($errores > 0) {
            $this->warn("Sync completado con {$errores} error(es).");
            return 1;
        }

        $this->info('Sync completado OK.');
        return 0;
    }
}
