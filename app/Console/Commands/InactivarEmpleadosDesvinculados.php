<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * php artisan rrhh:inactivar-desvinculados
 *
 * Revisa todas las desvinculaciones (despidos y renuncias) cuya fecha_efectiva
 * sea hoy o anterior y cuyo empleado aún esté activo en la tabla "empleados"
 * (tabla core / pgsql), los marca como activo = false, limpia plaza_id y
 * libera la plaza (estado → 'vacante') si ningún otro activo la ocupa.
 *
 * Pensado para ejecutarse cada mañana (06:00) vía el scheduler de Laravel.
 */
class InactivarEmpleadosDesvinculados extends Command
{
    protected $signature = 'rrhh:inactivar-desvinculados
                            {--dry-run : Muestra los empleados que serían inactivados sin hacer nada}';

    protected $description = 'Inactiva empleados cuya fecha efectiva de desvinculación ya llegó.';

    public function handle(): int
    {
        $hoy    = now()->toDateString();
        $dryRun = $this->option('dry-run');

        // Primero: IDs de empleados que aún están activos (conexión core pgsql)
        // Se convierten a string porque empleado_id en desvinculaciones es text
        $activosIds = DB::connection('pgsql')
            ->table('empleados')
            ->where('activo', true)
            ->pluck('id')
            ->map(fn($id) => (string) $id)
            ->all();

        if (empty($activosIds)) {
            $this->info('No hay empleados activos en el sistema.');
            return 0;
        }

        // Desvinculaciones cuya fecha efectiva ya llegó y el empleado aún está activo.
        // Excluir las que están pendientes de aprobación (pendiente_gerencia_ops).
        $pendientes = DB::connection('rrhh')
            ->table('desvinculaciones as dv')
            ->select('dv.id', 'dv.empleado_id', 'dv.tipo', 'dv.fecha_efectiva',
                     'dv.empleado_nombre')
            ->where('dv.fecha_efectiva', '<=', $hoy)
            ->whereIn('dv.empleado_id', $activosIds)
            ->where(function ($q) {
                $q->whereNull('dv.estado')
                  ->orWhere('dv.estado', '!=', 'pendiente_gerencia_ops');
            })
            ->get();

        if ($pendientes->isEmpty()) {
            $this->info('No hay empleados pendientes de inactivar.');
            return 0;
        }

        $this->line("Empleados a inactivar: {$pendientes->count()}");

        foreach ($pendientes as $dv) {
            $label = $dv->empleado_nombre ?? "ID #{$dv->empleado_id}";
            $this->line("  → {$label} ({$dv->tipo}) — fecha efectiva: {$dv->fecha_efectiva}");

            if (!$dryRun) {
                // Obtener plaza actual antes de inactivar
                $empleado = DB::connection('pgsql')
                    ->table('empleados')
                    ->where('id', $dv->empleado_id)
                    ->select('plaza_id')
                    ->first();

                DB::connection('pgsql')
                    ->table('empleados')
                    ->where('id', $dv->empleado_id)
                    ->update([
                        'activo'       => false,
                        'plaza_id'     => null,
                        'aud_usuario'  => 'sistema:inactivar-desvinculados',
                        'updated_at'   => now(),
                    ]);

                // Liberar la plaza si ningún otro activo la ocupa
                if ($empleado?->plaza_id) {
                    $otrosActivos = DB::connection('pgsql')
                        ->table('empleados')
                        ->where('plaza_id', $empleado->plaza_id)
                        ->where('activo', true)
                        ->count();

                    if ($otrosActivos === 0) {
                        DB::connection('pgsql')
                            ->table('plazas')
                            ->where('id', $empleado->plaza_id)
                            ->update(['estado' => 'vacante', 'updated_at' => now()]);

                        $this->line("    Plaza liberada (id={$empleado->plaza_id}) → vacante");
                    }
                }

                Log::channel('daily')->info('rrhh:inactivar-desvinculados', [
                    'empleado_id'    => $dv->empleado_id,
                    'empleado_nombre'=> $label,
                    'tipo'           => $dv->tipo,
                    'fecha_efectiva' => $dv->fecha_efectiva,
                    'plaza_liberada' => $empleado?->plaza_id,
                ]);
            }
        }

        if ($dryRun) {
            $this->warn('Modo --dry-run: no se realizó ningún cambio.');
        } else {
            $this->info("Se inactivaron {$pendientes->count()} empleado(s).");
        }

        return 0;
    }
}
