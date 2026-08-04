<?php

namespace App\Console\Commands;

use App\Models\RRHH\Traslado;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AplicarTrasladosPendientes extends Command
{
    protected $signature   = 'rrhh:aplicar-traslados';
    protected $description = 'Aplica al empleado los traslados aprobados cuya fecha_efectiva ya llegó';

    public function handle(): int
    {
        $pendientes = Traslado::where('estado', 'aprobado')
            ->whereNull('aplicado_at')
            ->whereDate('fecha_efectiva', '<=', now()->toDateString())
            ->get();

        if ($pendientes->isEmpty()) {
            $this->info('Sin traslados pendientes de aplicar.');
            return 0;
        }

        $ok = 0;
        foreach ($pendientes as $traslado) {
            try {
                $updates = ['updated_at' => now()];

                $esCambioDePlaza = $traslado->sucursal_origen_id &&
                    (int) $traslado->sucursal_origen_id === (int) $traslado->sucursal_destino_id;

                if ($esCambioDePlaza) {
                    if ($traslado->cargo_destino_id) {
                        $updates['cargo_id'] = $traslado->cargo_destino_id;
                    }
                } else {
                    $updates['sucursal_id'] = $traslado->sucursal_destino_id;
                    if ($traslado->cargo_destino_id) {
                        $updates['cargo_id'] = $traslado->cargo_destino_id;
                    }
                }

                DB::connection('pgsql')
                    ->table('empleados')
                    ->where('id', $traslado->empleado_id)
                    ->update($updates);

                $traslado->update(['aplicado_at' => now()]);

                $tipo = $esCambioDePlaza ? 'cambio de plaza' : 'traslado';
                $this->line("  ✅ [{$tipo}] empleado_id={$traslado->empleado_id} (traslado #{$traslado->id})");
                $ok++;
            } catch (\Throwable $e) {
                $this->error("  ❌ traslado #{$traslado->id}: {$e->getMessage()}");
            }
        }

        $this->info("Aplicados: {$ok} / {$pendientes->count()}");
        return 0;
    }
}
