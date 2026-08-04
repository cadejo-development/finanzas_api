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

                $pgsql = DB::connection('pgsql');

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

                // ── Plazas ────────────────────────────────────────────────
                if ($traslado->cargo_destino_id) {
                    $empleado = $pgsql->table('empleados')
                        ->where('id', $traslado->empleado_id)->first();

                    $motivo = $esCambioDePlaza ? 'cambio_de_plaza' : 'traslado';

                    if ($empleado?->plaza_id) {
                        $pgsql->table('plaza_historial')
                            ->where('plaza_id', $empleado->plaza_id)
                            ->where('empleado_id', $traslado->empleado_id)
                            ->whereNull('fecha_fin')
                            ->update([
                                'fecha_fin'     => $traslado->fecha_efectiva,
                                'motivo_salida' => $motivo,
                                'updated_at'    => now(),
                            ]);
                    }

                    $q = $pgsql->table('plazas as p')
                        ->join('departamentos as d', 'd.id', '=', 'p.departamento_id')
                        ->leftJoin('empleados as e', fn($j) =>
                            $j->on('e.plaza_id', '=', 'p.id')->where('e.activo', true))
                        ->where('p.cargo_id', $traslado->cargo_destino_id)
                        ->where('p.activo', true)
                        ->whereNull('e.id');

                    if ($traslado->departamento_destino_id) {
                        $q->where('p.departamento_id', $traslado->departamento_destino_id);
                    } else {
                        $q->where('d.sucursal_id', $traslado->sucursal_destino_id);
                    }

                    $plazaDestino = $q->orderBy('p.id')->select('p.id')->first();

                    if ($plazaDestino) {
                        $updates['plaza_id'] = $plazaDestino->id;
                        $pgsql->table('plaza_historial')->insert([
                            'plaza_id'       => $plazaDestino->id,
                            'empleado_id'    => $traslado->empleado_id,
                            'motivo_entrada' => $motivo,
                            'fecha_inicio'   => $traslado->fecha_efectiva,
                            'aud_usuario'    => $traslado->aud_usuario ?? 'sistema',
                            'created_at'     => now(),
                            'updated_at'     => now(),
                        ]);
                    }
                }

                $pgsql->table('empleados')
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
